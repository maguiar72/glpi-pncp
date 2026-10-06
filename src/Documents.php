<?php

/**
 * -------------------------------------------------------------------------
 * PNCP plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of PNCP plugin for GLPI.
 *
 * PNCP plugin for GLPI is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PNCP plugin for GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PNCP plugin for GLPI. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Pncp;

use CommonDBTM;
use Document;
use Document_Item;
use DocumentCategory;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Pncp\Api\PncpException;
use Session;
use Throwable;

/**
 * Files published on PNCP (edital, contract, term of reference, ata...) found
 * from a GLPI contract or a PNCP record, and imported as GLPI documents linked
 * to it. Each portal file is downloaded once; importing it again only links it.
 */
final class Documents
{
    /** Items whose "Documents" tab shows the PNCP panel. */
    public const ITEMTYPES = [\Contract::class, Contract::class, Procurement::class, PriceRecord::class];

    /** POST_SHOW_TAB hook: PNCP panel under the Documents tab. */
    public static function postShowTab(array $params): void
    {
        $item = $params['item'] ?? null;
        if (($params['options']['itemtype'] ?? '') !== Document_Item::class
            || !$item instanceof CommonDBTM
            || !in_array($item::class, self::ITEMTYPES, true)
            || !Session::haveRight(Install::RIGHT, READ)
        ) {
            return;
        }
        $sources = self::sources($item);
        if ($sources === []) {
            return; // e.g. a GLPI contract not copied from PNCP
        }
        TemplateRenderer::getInstance()->display('@pncp/documents_panel.html.twig', [
            'url'     => Menu::url('/front/documents.php') . '?' . http_build_query(['itemtype' => $item::class, 'items_id' => $item->getID()]),
            'sources' => $sources,
        ]);
    }

    /**
     * PNCP records whose files are relevant for an item.
     *
     * @return array<int, array{kind: string, label: string, cnpj: string, year: int, seq: int, ata: ?int}>
     */
    public static function sources(CommonDBTM $item): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $sources = [];
        $contracts = [];
        if ($item instanceof \Contract) {
            $contracts = iterator_to_array($DB->request(['FROM' => Contract::getTable(), 'WHERE' => ['contracts_id' => $item->getID()]]), false);
        } elseif ($item instanceof Contract) {
            $contracts = [$item->fields];
        }
        foreach ($contracts as $c) {
            $sources[] = ['kind' => 'contratos', 'label' => $c['name'], 'cnpj' => $c['cnpj'], 'year' => (int) $c['year'], 'seq' => (int) $c['sequential'], 'ata' => null];
            if ($p = self::parseControl((string) $c['procurement_control_number'])) {
                $sources[] = ['kind' => 'compras', 'label' => self::procurementLabel($c['procurement_control_number'])] + $p;
            }
        }
        if ($item instanceof Procurement) {
            $sources[] = ['kind' => 'compras', 'label' => $item->fields['name'], 'cnpj' => $item->fields['cnpj'], 'year' => (int) $item->fields['year'], 'seq' => (int) $item->fields['sequential'], 'ata' => null];
        }
        if ($item instanceof PriceRecord && ($p = self::parseControl((string) $item->fields['control_number']))) {
            $sources[] = ['kind' => 'atas', 'label' => $item->fields['name']] + $p;
            $sources[] = ['kind' => 'compras', 'label' => self::procurementLabel($item->fields['procurement_control_number'])] + ['ata' => null] + $p;
        }

        // Same source reached twice (two contracts of one procurement): keep one.
        $unique = [];
        foreach ($sources as $s) {
            $unique[self::sourceKey($s)] = $s;
        }

        return array_values(array_filter($unique, static fn($s) => $s['cnpj'] !== '' && $s['seq'] > 0));
    }

    /** "CNPJ-1-000569/2025" (procurement) or "CNPJ-1-002116/2025-000001" (ata) → pieces. */
    private static function parseControl(string $control): ?array
    {
        if (!preg_match('/^(\w{14})-\d+-(\d+)\/(\d{4})(?:-(\d+))?$/', $control, $m)) {
            return null;
        }

        return ['cnpj' => $m[1], 'year' => (int) $m[3], 'seq' => (int) $m[2], 'ata' => isset($m[4]) ? (int) $m[4] : null];
    }

    private static function procurementLabel(?string $control): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $control ? $DB->request(['SELECT' => 'name', 'FROM' => Procurement::getTable(), 'WHERE' => ['control_number' => $control]])->current() : null;

        return $row['name'] ?? sprintf(__('Source procurement %s', 'pncp'), (string) $control);
    }

    private static function sourceKey(array $s): string
    {
        return implode('/', array_filter([$s['kind'], $s['cnpj'], $s['year'], $s['seq'], $s['ata']], static fn($v) => $v !== null));
    }

    /**
     * Files of every source, flagged when already imported / linked to the item.
     *
     * @return array{files: array, errors: string[]}
     */
    public static function listFiles(CommonDBTM $item): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $api = Config::api((int) Config::get('web_budget'));
        $files = [];
        $errors = [];
        foreach (self::sources($item) as $s) {
            try {
                $list = $api->files($s['kind'], $s['cnpj'], $s['year'], $s['seq'], $s['ata']);
            } catch (PncpException $e) {
                $errors[] = $s['label'] . ': ' . $e->getMessage();
                continue;
            }
            foreach ($list as $f) {
                $key = self::sourceKey($s) . '/' . (int) ($f['sequencialDocumento'] ?? 0);
                $files[$key] = [
                    'key'    => $key,
                    'source' => $s['label'],
                    'title'  => (string) ($f['titulo'] ?? ''),
                    'type'   => (string) ($f['tipoDocumentoNome'] ?? ''),
                    'date'   => Util::showDate($f['dataPublicacaoPncp'] ?? null),
                    'url'    => (string) ($f['url'] ?? $f['uri'] ?? ''),
                ];
            }
        }

        // Already imported? Linked to this item?
        if ($files !== []) {
            $mapped = [];
            foreach ($DB->request(['FROM' => 'glpi_plugin_pncp_documents', 'WHERE' => ['source_key' => array_keys($files)]]) as $m) {
                $mapped[$m['source_key']] = (int) $m['documents_id'];
            }
            foreach ($files as $key => &$f) {
                $docId = $mapped[$key] ?? 0;
                $f['documents_id'] = $docId;
                $f['document_url'] = $docId ? Document::getFormURLWithID($docId) : null;
                $f['linked'] = $docId > 0 && countElementsInTable(Document_Item::getTable(), [
                    'documents_id' => $docId,
                    'itemtype'     => $item::class,
                    'items_id'     => $item->getID(),
                ]) > 0;
            }
            unset($f);
        }

        return ['files' => array_values($files), 'errors' => $errors];
    }

    /**
     * Import the selected files ("key|url|title") as GLPI documents linked to $item
     * (and, for a PNCP contract copied to GLPI, to the GLPI contract too).
     *
     * @param string[] $selected
     * @return array{ok: int, errors: string[], partial: bool}
     */
    public static function import(CommonDBTM $item, array $selected): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $api = Config::api((int) Config::get('web_budget'));
        $targets = [[$item::class, $item->getID()]];
        if ($item instanceof Contract && (int) $item->fields['contracts_id'] > 0) {
            $targets[] = [\Contract::class, (int) $item->fields['contracts_id']];
        }
        $entity = (int) ($item->fields['entities_id'] ?? Session::getActiveEntity());
        $category = (int) Dropdown::import(DocumentCategory::class, ['name' => 'PNCP']);
        $valid = array_column(self::listFiles($item)['files'], null, 'key');

        $ok = 0;
        $errors = [];
        $partial = false;
        foreach ($selected as $key) {
            $file = $valid[$key] ?? null;
            if ($file === null) {
                $errors[] = sprintf(__('File %s is no longer listed on PNCP.', 'pncp'), $key);
                continue;
            }
            try {
                $docId = (int) $file['documents_id'];
                if ($docId === 0 || !(new Document())->getFromDB($docId)) {
                    $docId = self::download($api, $file, $entity, $category);
                    $DB->updateOrInsert('glpi_plugin_pncp_documents', [
                        'url' => $file['url'], 'documents_id' => $docId, 'date_mod' => date('Y-m-d H:i:s'),
                    ], ['source_key' => $key]);
                }
                foreach ($targets as [$itemtype, $itemsId]) {
                    $link = new Document_Item();
                    if (!$link->getFromDBByCrit(['documents_id' => $docId, 'itemtype' => $itemtype, 'items_id' => $itemsId])) {
                        $link->add(['documents_id' => $docId, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
                    }
                }
                $ok++;
            } catch (PncpException $e) {
                if ($e->budgetExhausted) {
                    $partial = true;
                    break;
                }
                $errors[] = $file['title'] . ': ' . $e->getMessage();
            } catch (Throwable $e) {
                $errors[] = $file['title'] . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'errors' => $errors, 'partial' => $partial];
    }

    /**
     * Link to a GLPI contract the documents already imported for the PNCP sources of a PNCP contract
     * (its own files and its procurement's). Idempotent: existing links are kept, nothing is downloaded.
     * Returns the number of links created.
     */
    public static function linkKnownTo(Contract $pncp, int $glpiContractId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $n = 0;
        foreach (self::sources($pncp) as $s) {
            $prefix = self::sourceKey($s) . '/';
            $rows = $DB->request(['FROM' => 'glpi_plugin_pncp_documents', 'WHERE' => ['source_key' => ['LIKE', $prefix . '%']]]);
            foreach ($rows as $m) {
                // contratos/CNPJ/2026/5 must not catch contratos/CNPJ/2026/50/…: only "<prefix><number>" is ours
                if (!ctype_digit(substr((string) $m['source_key'], strlen($prefix)))) {
                    continue;
                }
                $doc = new Document();
                if (!$doc->getFromDB((int) $m['documents_id']) || $doc->fields['is_deleted']) {
                    continue;
                }
                $link = new Document_Item();
                $crit = ['documents_id' => $doc->getID(), 'itemtype' => \Contract::class, 'items_id' => $glpiContractId];
                if (!$link->getFromDBByCrit($crit) && $link->add($crit)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Download one portal file into a new GLPI document; returns its id. */
    private static function download(\GlpiPlugin\Pncp\Api\Pncp $api, array $file, int $entity, int $category): int
    {
        $prefix = uniqid('pncp', true) . '_';
        $tmp = GLPI_TMP_DIR . '/' . $prefix . 'download';
        try {
            $name = $api->client->download($file['url'], $tmp);
            // The portal name sometimes lacks an extension: GLPI needs one to accept the file.
            if (!preg_match('/\.[a-z0-9]{2,5}$/i', $name)) {
                $name .= self::guessExtension($tmp);
            }
            $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?: 'pncp.pdf';
            $final = $prefix . $name;
            rename($tmp, GLPI_TMP_DIR . '/' . $final);
            $doc = new Document();
            $docId = (int) $doc->add([
                'name'                  => Util::truncate(trim($file['title'] ?: $name), 255),
                'entities_id'           => $entity,
                'is_recursive'          => 1,
                'documentcategories_id' => max(0, $category),
                'comment'               => trim($file['type'] . "\n" . $file['source'] . "\nPNCP: " . $file['url']),
                '_filename'             => [$final],
                '_prefix_filename'      => [$prefix],
            ]);
            if ($docId === 0) {
                throw new \RuntimeException(sprintf(__('GLPI refused the file %s (type not allowed in Setup > Dropdowns > Document types?)', 'pncp'), $name));
            }

            return $docId;
        } finally {
            foreach (glob(GLPI_TMP_DIR . '/' . $prefix . '*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    private static function guessExtension(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        return match (true) {
            str_starts_with($head, '%PDF')         => '.pdf',
            str_starts_with($head, "PK\x03\x04")   => '.zip',
            str_starts_with($head, "\xD0\xCF\x11\xE0") => '.doc',
            default                                => '.pdf',
        };
    }

    /** Page front/documents.php. */
    public static function showPage(CommonDBTM $item): void
    {
        $result = self::listFiles($item);
        TemplateRenderer::getInstance()->display('@pncp/documents.html.twig', [
            'item_name'  => $item->getName(),
            'item_url'   => $item->getLinkURL() . '&forcetab=Document_Item$1',
            'itemtype'   => $item::class,
            'items_id'   => $item->getID(),
            'files'      => $result['files'],
            'errors'     => $result['errors'],
            'sources'    => self::sources($item),
            'glpi_contract_url' => $item instanceof Contract && (int) $item->fields['contracts_id'] > 0
                ? \Contract::getFormURLWithID((int) $item->fields['contracts_id']) . '&forcetab=Document_Item$1' : null,
            'can_import' => Session::haveRight(Install::RIGHT, UPDATE) && Document::canCreate(),
            'action'     => Menu::url('/front/documents.php'),
        ]);
    }
}
