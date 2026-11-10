# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, a sample file and steps to reproduce. You
will receive a reply within a few working days.

## Scope

This package imports untrusted files into your database and writes exports that people open in
office applications. Reports are especially welcome for:

- SQL injection through cell values, match keys or canonicalizer prefilters;
- formula injection in exported CSV, XLSX or ODS files or in failed-rows files;
- sensitive field values or raw database error messages leaking into failed-rows files, events or
  job failure messages;
- temporary files (downloads, failed-rows files, the in-file duplicate key store) that are left
  behind or created with unsafe permissions;
- inputs that make an import's memory or time grow without bound.

File parsing itself (zip bombs, XML entities, size limits) is done by
[intragile/php-spreadsheet](https://github.com/intragile/php-spreadsheet); reports about it are
welcome at the same address.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
