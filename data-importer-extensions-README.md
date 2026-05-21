# Data Importer Extensions Bundle

Extensions for [Pimcore's Data Importer](https://github.com/pimcore/data-importer) that add memory-efficient interpreters, bulk database loading, dynamic path syntax, and additional data targets and operators.

Maintained by [Torq IT](https://torqit.com).

---

## Why this bundle

The stock Data Importer works well for small files and straightforward mappings, but breaks down on large feeds and complex object layouts. This bundle addresses three pain points:

- **Memory** — XLSX parsing via PHPOffice loads the workbook into memory. The OpenSpout-based interpreter streams it instead.
- **Throughput** — Queueing rows one at a time is slow. Bulk interpreters use `LOAD DATA LOCAL INFILE` to load 200K rows in under 5 seconds.
- **Object placement** — The default importer puts every imported object in a single folder. The path-syntax feature derives the target folder from row data (`/Products/$[Make]/$[Model]`).

## Requirements

| | |
|---|---|
| Pimcore Platform | 2025.1+ |
| PHP | per Pimcore 2025.1 requirements |
| Database | MySQL/MariaDB with `LOCAL INFILE` enabled (only for bulk interpreters) |
| License | Pimcore Open Core License (POCL) |

## Installation

```bash
composer require torqit/data-importer-extensions-bundle
```

Enable the bundle in `config/bundles.php`:

```php
return [
    // ...
    TorqIT\DataImporterExtensionsBundle\TorqITDataImporterExtensionsBundle::class => ['all' => true],
];
```

To use the bulk interpreters, enable `LOCAL INFILE` on the database connection in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        connections:
            default:
                options:
                    1001: true   # PDO::MYSQL_ATTR_LOCAL_INFILE
```

The MySQL server must also be configured with `local_infile=ON`.

---

## Features

### Interpreters

Interpreters parse the incoming file into rows the importer can process. The bundle ships four:

#### Advanced XLSX Interpreter

Drop-in replacement for the default XLSX interpreter. Uses [OpenSpout](https://github.com/openspout/openspout) to stream the workbook instead of loading it whole. Use this when files are large enough to OOM the default interpreter but small enough that you don't need bulk loading.

#### Bulk XLSX Interpreter

Converts the XLSX file to CSV on disk, then bulk-loads rows into the importer's queue table with `LOAD DATA LOCAL INFILE`. Designed for very large feeds — benchmarks show 200K rows queued in under 5 seconds vs. minutes with row-by-row insertion.

#### Bulk CSV Interpreter

Same bulk-loading mechanism, skipping the XLSX-to-CSV conversion step. Use for CSV sources.

#### XML Schema-Based Preview Interpreter

Extends the default XML interpreter so the field-mapping preview in the Pimcore admin UI populates from an attached XSD instead of inferring fields from the sample document. Useful for sparse XML where the sample doesn't expose every possible field.

### Data Loaders

#### Bulk SQL Data Loader

Loads rows from any Doctrine DBAL-compatible database. Pair with the **Bulk SQL Interpreter** to stream SQL result sets into the importer queue using the same bulk mechanism as the file-based interpreters.

### Path Syntax

Lets the importer place created objects at a path derived from the row. Replaces the static "object folder" setting with a template that's expanded per row.

**Excel / CSV** (positional references):

```
/Products/Cars/$[1]/$[2]/$[0]
```

**XML** (named references):

```
/Products/Cars/$[Make]/$[Model]/$[Year]
```

**Regex extraction** — pull a substring out of a column:

```
/Products/$[1|/^([A-Z]{3})/]
```

**Regex substitution** — rewrite a column value:

```
/Products/$[1|/\s+/_/]
```

Used by the Advanced Path Strategy (below) for element loading and creation as well.

### Data Targets

Additional targets that can be selected per-field in the import definition:

- **Advanced Classification Store** — write to classification store fields, with explicit overwrite control (default Pimcore behavior is to merge).
- **Image Gallery Appender** — append to an image gallery field instead of replacing it. Lets repeated imports accumulate assets.
- **Property** — set object properties (the system-level key/value properties, not class fields).
- **Tags** — apply Pimcore tags to the imported element.

### Operators

Transforms applied in the field-mapping pipeline:

- **Constants** — emit a fixed value regardless of input.
- **SafeKey** — sanitize a value for use as a Pimcore element key (strips/replaces unsafe characters).
- **Import Asset Advanced** — download an asset from a URL with control over the destination path and asset properties.
- **Arithmetic** — add/subtract/multiply/divide column values.
- **Regex Replace** — pattern-based string substitution.
- **Country Code** — normalize/validate country codes.

### Element Loading & Creation

Strategies that decide which existing object to update (or where to create a new one):

- **Advanced Path Strategy** — load/create elements using the path-syntax template above. Combine with the path-syntax feature to drive both placement and lookup from row data.
- **Property-based loader** — match existing elements by a Pimcore property rather than by key or ID.

---

## Configuration example

A minimal import definition using the bulk XLSX interpreter and advanced path strategy:

```yaml
# In the Pimcore Data Importer admin UI, the corresponding settings would be:
#
# Interpreter:      Bulk XLSX
# Element Loader:   Advanced Path Strategy
#   Path Template:  /Products/$[Make]/$[Model]
# Field Mapping:
#   - column "Name"   -> target "key" with SafeKey operator
#   - column "Price"  -> target "price"
#   - column "Image"  -> target "image" with Import Asset Advanced operator
```

## Performance notes

The bulk interpreters bypass the per-row Doctrine insert path used by the standard importer. The trade-offs:

- Requires `LOCAL INFILE` on both client and server.
- Skips per-row PHP-level validation during queueing — validation still runs in the processing phase.
- Errors during the `LOAD DATA` step surface as MySQL warnings; check the importer log if rows go missing.

For files under ~10K rows, the Advanced XLSX interpreter is usually enough. Switch to bulk only when queueing time becomes a problem.

## License

Distributed under the [Pimcore Open Core License (POCL)](https://github.com/pimcore/pimcore/blob/11.x/LICENSE.md). See `LICENSE` in this repository.

## Contributing

Issues and pull requests welcome. Please include:

- Pimcore version
- A minimal import definition that reproduces the issue
- For bulk-loader bugs, the MySQL server version and `local_infile` setting

## Links

- [Pimcore Data Importer](https://github.com/pimcore/data-importer) — the upstream bundle this extends
- [OpenSpout](https://github.com/openspout/openspout) — the streaming spreadsheet library used by the Advanced XLSX interpreter
- [Torq IT](https://torqit.com)
