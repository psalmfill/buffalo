# Loading production data

The order and commands for loading reference data into a new production
system, after [installation.md](installation.md) and before go-live. Every
place name and boundary comes from files supplied by the project owner;
BUFFALO never invents them.

Commands assume the wrapper from the [runbook](runbook.md):

    CONFIG=/etc/buffalo/buffalo.env
    dc() { (cd /opt/buffalo/current && sudo docker compose --env-file "$CONFIG" "$@"); }

The owner's files are given to the one-off `tools` container read-only with
`-v`; they are never copied into the images or volumes. Keep the originals
with the project records.

## 0. Check the starting point

    dc run --rm tools php artisan buffalo:data:status

It lists the hierarchy and boundary counts, communities, the reference data
version, what is missing, and any demo or synthetic data. A production
installation never contains demo data: the generators
(`buffalo:generate-demo-boundaries`, `buffalo:seed-demo-incidents`,
`DevelopmentHierarchySeeder`) are not even registered when
`APP_ENV=production`. The command exits 1 until the required data is loaded.

## 1. Administrative hierarchy

Files: `senatorial_districts.csv`, `lgas.csv` and, optionally, `wards.csv`, in
the format described in `database/data/README.md` (shipped in the source; ask
the BUFFALO maintainers for a copy). The 6 zones and 37 states were loaded by
the installer.

    # Validate only: every rule, every row, nothing written
    dc run --rm -v /srv/buffalo-data/hierarchy:/import:ro tools \
        php artisan buffalo:import-hierarchy --path=/import --dry-run

    # Import
    dc run --rm -v /srv/buffalo-data/hierarchy:/import:ro tools \
        php artisan buffalo:import-hierarchy --path=/import

The import refuses totals other than 37 states, 109 senatorial districts and
774 LGAs unless `--allow-count-mismatch` is given. Do not use that flag for
production: correct the files instead. It is safe to run again (upsert by
code); `--deactivate-missing` deactivates places that are no longer in the
files (they are never deleted).

## 2. Boundaries

A GeoJSON FeatureCollection of LGA boundaries (and, if supplied, state
boundaries; otherwise state boundaries are derived from the LGAs). Each
feature must be matched to an LGA, either through properties or a mapping
file. The attribution is required: it is shown on every map.

    dc run --rm -v /srv/buffalo-data/boundaries:/import:ro tools \
        php artisan buffalo:import-boundaries /import/lgas.geojson --level=lga \
        --state-property=state_code --code-property=lga_code \
        --attribution="© <data owner>, <year>" --user=<your email> --dry-run

    # Then without --dry-run

Options: `--mapping=/import/mapping.csv` (`feature_id,state_code,lga_code`)
when the file has no usable codes; `--id-property` for the feature
identifier. The post-import report (with coordinates) is kept on the private
reports disk, as classified.

## 3. Reference data review and sign-off

BUFFALO ships default reference lists: incident types and their fields, threat
types, security agencies, organization sectors, religions and denominations,
and property types. Review them with the client before go-live:

1. Sign in as the Director General (or a super admin) and open
   **Reference data**. Add, rename or deactivate entries as agreed, and adjust
   incident type fields. Entries in use cannot be deleted, only deactivated.
2. Print the reference data version:

       dc run --rm tools php artisan buffalo:data:status

   The **Reference data** section shows each list's active count and
   fingerprint, and one overall version. Any later change produces a new
   version.
3. Record the sign-off: **System health → Go-live decisions → Reference data
   reviewed**, naming who signed off for the client and the version shown.
   The go-live check treats the sign-off as current only while the version is
   unchanged.

## 4. Communities (optional)

Communities can be entered by officers as they work, or loaded per LGA in bulk
with the community import (Phase 2B): **LGAs → (LGA) → Communities → Import**,
or **Communities → Import** for several LGAs. Download the template, fill it
in, upload it, check the preview (every row is validated before anything is
written) and confirm. Available to the DG, State Directors and Deputy State
Directors within their jurisdiction.

## 5. Check again

    dc run --rm tools php artisan buffalo:data:status

It exits 0 when the hierarchy and LGA boundaries are complete and no demo or
synthetic data exists. Then continue with the onboarding of users and the
go-live check (`buffalo:go-live:check`, see the [runbook](runbook.md)).
