# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.3.0] - unreleased

### Added

- Commitment notes (*empenhos*) of each contract: new `glpi_plugin_pncp_commitments` table, a "Commitments" panel on the PNCP contract page, collected on import, by *Refresh from PNCP* and by the monitoring (new option "Contract commitments (empenhos)", on by default). Source: `/v1/orgaos/{cnpj}/contratos/{ano}/{seq}/empenhos`; a 404 from the portal means "no commitment" and is recorded as such.
- Upgrade from 0.2.0 adds the `commitments_version` and `sync_commitments` columns; existing contracts get their commitments on the next synchronization.
- Copy to GLPI links a contract already registered by hand instead of duplicating it: same number/year (`00033/2026`, `33/26`, `Contrato 33-2026`) corroborated by supplier CNPJ, process or start date. What is already filled in is kept; empty fields are completed; the GLPI costs and suppliers are not duplicated.
- "GLPI contracts that look like this one" panel on the PNCP contract page with a "Link to this contract" button (merge); a copy created earlier by the plugin goes to the GLPI trash.
- "Open GLPI contract" / "Open GLPI supplier" buttons on the PNCP contract page, and "Open GLPI contract" on the PNCP documents page.

### Changed

- Dates on every plugin screen follow the GLPI display format (they were shown as `2026-09-30`); the end date written in the GLPI contract comment is `dd/mm/yyyy`.
- Copying a contract to GLPI links the documents already imported for its PNCP sources (contract and procurement); after importing documents a message says whether they reached the GLPI contract.
- `plugins:pncp:selftest` checks the date and contract-number helpers.

### Changed

- Neutral examples and placeholders (no organization-specific CNPJ, unit code or name in help texts, README or comments). The `selftest --network` live search by organization now takes `--cnpj` and `--unit`; without them that single check is skipped.

### Fixed

- "Monitor this organization/unit" opens the monitoring form already filled in with the CNPJ and unit (the values were lost in the form tab request).

### Fixed

- Portuguese: the "Year" column/filter was not translated (now "Ano").
- Value columns of the lists (estimated, awarded, global, unit and total values) are shown as currency (`R$ 1.234,56`) instead of the raw decimal (display hook `plugin_pncp_giveItem`); the columns stay decimal, so filters such as `>1000000` and sorting keep working.

## [0.2.0] - unreleased

### Added

- Contract lifecycle (not started, active, extended, expired, inactive/terminated) from dates and amendments, recomputed daily; mapped to GLPI "Statuses of items" created under "Contrato".
- PNCP documents: "Search documents on PNCP" panel on the Documents tab of GLPI contracts and PNCP records; selected files are imported as GLPI documents (category "PNCP"), downloaded once.
- `plugins:pncp:rebuild` console command (re-derive records from their raw JSON).

### Changed

- Short descriptive titles: "Contrato 32/2025 – Suporte técnico mensal para o software Pergamum".
- Copy to GLPI contracts never overwrites a field edited in GLPI since the last copy.
- The supplier is imported with the contract; "copy suppliers automatically" is on by default and covers contract suppliers.
- A single "PNCP" sidebar entry and a shortcut bar on every plugin page.

## [0.1.0] - unreleased

### Added

- Interactive search on PNCP by name/text, CNPJ, unit and year (procurements, contracts, price registration records).
- On-demand import: procurements with items and results, contracts with amendments, price records with their procurement.
- Monitorings with incremental, resumable synchronization through the `pncpsync` automatic action: procurements, items/results, contracts, amendments, price records and PCA.
- Copy to GLPI Contracts and Suppliers: on demand, by massive action, or automatically per monitoring. PNCP tab on GLPI contracts and suppliers.
- Resilient HTTP client ported from `motor-pncp`: sequential pacing, retries with backoff, `Retry-After`, failure never treated as absence, per-run time budget.
- Console commands: `plugins:pncp:watch:add`, `plugins:pncp:sync`, `plugins:pncp:search`, `plugins:pncp:selftest`.
- English and Brazilian Portuguese translations.
