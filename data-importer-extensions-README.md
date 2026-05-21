# Data Importer Extensions Bundle

Pimcore Data Importer add-ons for large feeds and dynamic object placement.

> Streams huge XLSX files. Bulk-loads CSVs into the importer queue. Places objects at row-derived paths like `/Products/$[Make]/$[Model]`. Adds extra targets, operators, and element loaders.

```bash
composer require torqit/data-importer-extensions-bundle
```

## What you get

A drop-in set of interpreters, operators, targets, and loaders that plug into [Pimcore's Data Importer](https://github.com/pimcore/data-importer) — visible as new options in the standard import-definition UI. Nothing here replaces the importer; it extends it.

The headline pieces:

| Piece | Replaces / adds | Win |
|---|---|---|
| **Advanced XLSX interpreter** | the default XLSX interpreter | Constant memory via OpenSpout streaming |
| **Bulk XLSX / CSV interpreters** | adds | `LOAD DATA LOCAL INFILE` — 200K rows queued in <5s |
| **Bulk SQL loader + interpreter** | adds | Same bulk path, sourced from any Doctrine DBAL DB |
| **XML schema preview interpreter** | extends the default XML interpreter | Field mapping populated from an XSD, not the sample doc |
| **Path syntax** | replaces static "object folder" | `/Products/$[Make]/$[Model]/$[Year]` per row |
| **Element loaders** | adds | Match existing elements by path template or property |
| **Targets** | adds | Image-gallery append, properties, tags, classification-store overwrite |
| **Operators** | adds | Constants, SafeKey, Import Asset Advanced, Arithmetic, Regex Replace, Country Code |

## Requirements

Read off `composer.json`:

- Pimcore `^12.0`
- `pimcore/data-importer` `^2.0`
- `pimcore/admin-ui-classic-bundle` `^2.0`
- `openspout/openspout` `^4.0`
- `torq/pimcore-helpers-bundle` `^2.2.0`

Bulk interpreters additionally need MySQL/MariaDB with `LOCAL INFILE` enabled on both client and server.

## Install

```bash
composer require torqit/data-importer-extensions-bundle
```

Register the bundle:

```php
// config/bundles.php
return [
    // ...
    TorqIT\DataImporterExtensionsBundle\TorqITDataImporterExtensionsBundle::class => ['all' => true],
];
```

If you want the bulk interpreters, enable `LOCAL INFILE` on the Doctrine connection:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            default:
                options:
                    1001: true   # PDO::MYSQL_ATTR_LOCAL_INFILE
```

…and on the MySQL server (`local_infile=ON`). Without both, the bulk interpreters error out.

## Path syntax

Use anywhere the bundle accepts a path template — currently the Advanced Path Strategy element loader and creator.

| Form | Example | Notes |
|---|---|---|
| Positional column ref | `/Products/$[1]/$[0]` | Excel/CSV |
| Named column ref | `/Products/$[Make]/$[Model]` | XML |
| Regex extract | `/Products/$[1\|/^([A-Z]{3})/]` | Captures group 1 |
| Regex substitute | `/Products/$[1\|/\s+/_/]` | Search/replace |

## Recipes

### Big XLSX, group by two columns

```
Interpreter:     Bulk XLSX
Element loader:  Advanced Path Strategy
Path template:   /Products/$[Make]/$[Model]
Field mapping:
  - "Name"   -> key (via SafeKey operator)
  - "Price"  -> price
  - "Image"  -> image (via Import Asset Advanced)
```

### Append assets across re-imports

```
Field mapping:
  - "Image URLs" -> images (target: Image Gallery Appender)
```

Repeated imports add to the gallery instead of overwriting it.

### Match existing objects by SKU property

```
Element loader:  Property-based loader
Property name:   sku
Source column:   "SKU"
```

## Picking an interpreter

Rule of thumb:

- **<10K rows** → default or Advanced XLSX (Advanced XLSX if memory is an issue).
- **10K–100K rows** → Advanced XLSX is usually still fine.
- **100K+ rows, queueing is the bottleneck** → Bulk XLSX / Bulk CSV.
- **Source is a database, not a file** → Bulk SQL Data Loader + Bulk SQL Interpreter.

## Caveats

- Bulk interpreters skip per-row PHP validation at *queue* time. Validation still runs at *process* time — same as the standard importer — so this is about when errors surface, not whether.
- `LOAD DATA` failures surface as MySQL warnings. If rows go missing, the importer log is the first place to look.
- The Image Gallery Appender appends every time — if you re-run the same import, you get duplicates unless your input dedupes.

## License

See [`LICENSE.md`](LICENSE.md).

## Links

- [Pimcore Data Importer](https://github.com/pimcore/data-importer)
- [OpenSpout](https://github.com/openspout/openspout)
- [Torq IT](https://torqit.ca)
