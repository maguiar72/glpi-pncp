# PNCP plugin for GLPI

Brings data from the **Portal Nacional de Contratações Públicas** ([pncp.gov.br](https://pncp.gov.br)), the official Brazilian public procurement portal (Law 14.133/2021), into [GLPI](https://glpi-project.org) 11.

*Integra ao GLPI os dados do Portal Nacional de Contratações Públicas: pesquise, importe e mantenha sincronizadas contratações, contratos, atas e PCA de qualquer órgão, e copie contratos e fornecedores para os módulos nativos do GLPI.*

## Features

- **Search the portal** by name/free text, organization CNPJ, administrative unit code and year, for procurements (*editais/contratações*), contracts (*contratos/empenhos*) and price registration records (*atas de registro de preços*).
- **Import on demand** the selected records:
  - procurements with all items and results (winner, CNPJ/CPF, awarded prices);
  - contracts with their amendments (*termos aditivos*) and commitment notes (*empenhos*);
  - price registration records with their source procurement.
- **Monitorings**: keep one organization, or one of its units, synchronized automatically. Procurements, items/results, contracts, amendments, price records and the annual procurement plan (*PCA*) are updated by a GLPI automatic action, incrementally and resumably.
- **Copy to GLPI core objects**:
  - PNCP contracts become **GLPI Contracts**: number, type, start date, duration (amendments included), process number, global value as a contract cost, linked supplier;
  - suppliers become **GLPI Suppliers**, matched by CNPJ/CPF and never duplicated.

  Copies run on demand (buttons and massive actions) or automatically per monitoring. A **PNCP tab** on GLPI contracts and suppliers shows where the data came from.
- **Contract lifecycle** following ITIL supplier-management stages: *A iniciar*, *Ativo*, *Prorrogado*, *Vencido*, *Inativo*. It is computed from dates and amendments, recomputed daily, and applied to GLPI contracts as a "Status of items".
- **Fields edited in GLPI are respected.** A field of a GLPI contract changed by hand (name, status...) is never overwritten by later copies.
- **Documents**: the *Documents* tab of GLPI contracts and PNCP records has a *Search documents on PNCP* button. It lists the files published on the portal (edital, contract, term of reference, ata...) and imports the selected ones as GLPI documents.
- Every record keeps the **raw JSON** returned by the portal (*Raw data* tab), the source of truth for all other fields.
- Native GLPI lists, with search engine, filters and export (CSV, PDF...) for every record type.
- Translations: English and Brazilian Portuguese.

## Requirements

- GLPI 11.0.x
- PHP 8.2+ with curl
- Outbound HTTPS access to `pncp.gov.br`
- GLPI cron running in CLI mode, for monitorings

## Installation

1. Copy this directory to `glpi/plugins/pncp` (the directory **must** be named `pncp`).
2. Install and activate it, either in *Setup > Plugins* or from the command line:
   ```sh
   php bin/console plugin:install pncp -u <admin login>
   php bin/console plugin:activate pncp
   ```
3. Grant the **PNCP** right in *Administration > Profiles > (profile) > PNCP*. Super-Admin profiles get it on install.
4. Make sure the automatic action **pncpsync** runs: *Setup > Automatic actions*, mode *CLI*. The GLPI cron must be configured, e.g.:
   ```cron
   * * * * * php /path/to/glpi/front/cron.php
   ```

## Usage

- **Management > PNCP > Search PNCP**: search and import.
- **Management > PNCP > Monitorings**: add an organization CNPJ and, optionally, a unit, and choose what to collect and the start date. *Synchronize now* runs for up to one minute; the automatic action continues the rest.
- **Management > PNCP > PNCP contracts**: open a contract and click *Copy to GLPI contracts*, or select several and use the massive action.

### Command line

```sh
php bin/console plugins:pncp:watch:add --cnpj=<14-digit CNPJ> --unit=<unit code> --from=2026-01-01
php bin/console plugins:pncp:sync [id] [--budget=600] [--until-done]
php bin/console plugins:pncp:search "text" [--cnpj=] [--unit=] [--year=] [--type=edital|contrato|ata]
php bin/console plugins:pncp:rebuild            # after an upgrade
php bin/console plugins:pncp:selftest --user=<admin login> [--network [--cnpj=<CNPJ> [--unit=<unit code>]]]
```

## How synchronization works

The portal is slow and rate-limited (HTTP 429 from a WAF). The HTTP layer follows what was measured against the real portal in [`motor-pncp`](https://github.com/devtulio/motor-pncp) and [Licitarium](https://github.com/maguiar72/licitarium-free):

- requests are **sequential**, with a minimum interval: parallel connections only multiply 429 answers;
- transient failures (429, 5xx, timeouts, HTML error pages, 404 on listings) are retried with full-jitter backoff, honouring `Retry-After`;
- **a failure is never "no data"**: the per-phase cursor of a monitoring only moves past a date window when every query of that window succeeded, and is saved after each window, so a run cut by its time budget resumes where it stopped;
- procurements by CNPJ are collected in windows of one month (configurable): longer windows time out on large organizations;
- items/results and amendments are only fetched again for records whose portal update date changed.

Known portal quirks handled by the plugin:

- some units are registered twice, with and without leading zeros (`012345` and `12345`, different internal ids), and records may be published under either one;
- supplier identifiers can be a CNPJ, numeric or alphanumeric (since July 2026), or a CPF.

## Settings

*Setup > Plugins > PNCP*: window sizes, interval between synchronizations, time budgets of cron and interactive runs, minimum interval between requests, and the User-Agent sent to the portal. Identify your institution in the User-Agent.

## Development

- Code: `src/` (namespace `GlpiPlugin\Pncp`), front controllers in `front/`, Twig templates in `templates/`.
- Translations: `php tools/extract_pot.php` updates `locales/pncp.pot`; edit `locales/*.po`; `php tools/compile_mo.php` builds the `.mo` files.
- Smoke test against a real GLPI: `php bin/console plugins:pncp:selftest --user=<admin> --network --cnpj=<CNPJ> --unit=<unit code>` (`--cnpj`/`--unit` enable the live search by organization; without them that check is skipped). It renders every screen and runs a GLPI export inside a rolled-back transaction.

## License

[GPLv3+](LICENSE). Data from the PNCP is public (Law 14.133/2021, art. 174).

This plugin is not affiliated with the PNCP nor with the Brazilian government.
