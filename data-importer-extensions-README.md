# Data Importer Extensions Bundle

Extensions for [Pimcore's Data Importer](https://github.com/pimcore/data-importer): memory-efficient and bulk-loading interpreters, a dynamic path syntax for object placement, plus extra data targets, operators, and element-loader strategies.

Maintained by [Torq IT](https://torqit.ca).

## Contents

- [When to reach for this](#when-to-reach-for-this)
- [Requirements](#requirements)
- [Installation](#installation)
- [Interpreters](#interpreters)
- [Path syntax](#path-syntax)
- [Data targets](#data-targets)
- [Operators](#operators)
- [Element loaders](#element-loaders)
- [Performance notes](#performance-notes)
- [License](#license)

## When to reach for this

The stock Data Importer is fine for small files and flat object layouts. This bundle exists for three specific problems:

| Problem | What this bundle does |
|---|---|
| Large XLSX files OOM the importer | OpenSpout-based streaming interpreter |
| Queueing 100K+ rows takes minutes | `LOAD DATA LOCAL INFILE` bulk interpreters |
| Every imported object lands in one folder | Path-template syntax driven by row data |

It also adds smaller quality-of-life pieces — image-gallery append, property targets, tags, classification-store overwrite control, regex/arithmetic operators — listed below.

## Requirements

| | |
|---|---|
| Pimcore | `^12.0` |
| `pimcore/data-importer` | `^2.0` |
| `pimcore/admin-ui-classic-bundle` | `^2.0` |
| `openspout/openspout` | `^4.0` |
| `torq/pimcore-helpers-bundle` | `^2.2.0` |
| Database | MySQL/MariaDB with `LOCAL INFILE` enabled — bulk interpreters only |

See `composer.json` in the repo for the authoritative list.

## Installation

```bash
composer require torqit/data-importer-extensions-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    TorqIT\DataImporterExtensionsBundle\TorqITDataImporterExtensionsBundle::class => ['all' => true],
];
```

For the bulk interpreters, enable `LOCAL INFILE` on the Doctrine connection (`config/packages/doctrine.yaml`):

```yaml
doctrine:
    dbal:
        connections:
            default:
                options:
                    1001: true   # PDO::MYSQL_ATTR_LOCAL_INFILE
```

The MySQL server must also have `local_infile=ON`. Without both, the bulk interpreters fall back with an error.

## Interpreters

| Interpreter | Use it when | Mechanism |
|---|---|---|
| Advanced XLSX | XLSX files large enough to OOM PHPOffice but small enough that single-row queueing is fine | Streams the workbook with [OpenSpout](https://github.com/openspout/openspout) |
| Bulk XLSX | XLSX with 50K+ rows | Converts to CSV on disk, then `LOAD DATA LOCAL INFILE` into the queue table |
| Bulk CSV | Large CSV inputs | `LOAD DATA LOCAL INFILE` directly |
| XML schema-based preview | Sparse XML where the sample doc doesn't expose every field | Extends the default XML interpreter to populate the field-mapping preview from an attached XSD |

A **Bulk SQL Data Loader** pairs with a Bulk SQL Interpreter to stream rows from any Doctrine DBAL-compatible database through the same bulk path.

Reported throughput: **200K rows queued in under 5 seconds** with the bulk interpreters, versus minutes with row-by-row inserts.

## Path syntax

Replaces the static "object folder" setting with a template expanded per row. Use it to drive both *where* objects are created and *which* object an existing-element strategy matches.

**Excel/CSV — positional:**
```
/Products/Cars/$[1]/$[2]/$[0]
```

**XML — named:**
```
/Products/Cars/$[Make]/$[Model]/$[Year]
```

**Regex extraction:**
```
/Products/$[1|/^([A-Z]{3})/]
```

**Regex substitution:**
```
/Products/$[1|/\s+/_/]
```

## Data targets

Additional per-field targets selectable in the import definition:

- **Advanced Classification Store** — write to classification-store fields with explicit overwrite control (the default merges).
- **Image Gallery Appender** — appends to image gallery fields instead of replacing, so repeated imports accumulate.
- **Property** — sets element-level properties (the key/value system properties, not class fields).
- **Tags** — applies Pimcore tags to the imported element.

## Operators

Transforms applied in the field-mapping pipeline:

- **Constants** — emit a fixed value.
- **SafeKey** — sanitize a value for use as a Pimcore element key.
- **Import Asset Advanced** — download from a URL with control over destination path and asset properties.
- **Arithmetic** — add/subtract/multiply/divide column values.
- **Regex Replace** — pattern-based string substitution.
- **Country Code** — normalize/validate country codes.

## Element loaders

Strategies that decide which existing object to update (or where to create one):

- **Advanced Path Strategy** — load or create using the path-syntax template above.
- **Property-based loader** — match by Pimcore property rather than key/ID.

## Performance notes

The bulk interpreters bypass the per-row Doctrine insert used by the stock importer. Trade-offs:

- Requires `LOCAL INFILE` on both client (`PDO::MYSQL_ATTR_LOCAL_INFILE`) and server (`local_infile=ON`).
- Per-row PHP validation does not run during queueing — it runs in the processing phase, same as the standard importer.
- `LOAD DATA` errors surface as MySQL warnings; if rows go missing, check the importer log first.

Rule of thumb: under ~10K rows, the Advanced XLSX interpreter is enough. Switch to a bulk interpreter only when queueing time becomes the bottleneck.

## License

See [`LICENSE.md`](LICENSE.md) in this repository.

## Links

- [Pimcore Data Importer](https://github.com/pimcore/data-importer) — the upstream bundle this extends
- [OpenSpout](https://github.com/openspout/openspout) — the streaming spreadsheet library used by the Advanced XLSX interpreter
- [Torq IT](https://torqit.ca)
