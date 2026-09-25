# Hierarchy import fixtures

Synthetic data only (every name starts with "Fixture"). Used by
`tests/Feature/Hierarchy/ImportHierarchyCommandTest.php`.

- `valid/` is a complete, correct set of files (3 districts, 4 LGAs, 3 wards).
- Every other directory is an overlay: the test copies `valid/` to a temporary
  directory and then copies the overlay's files over it.
  - `invalid/<rule>/` breaks exactly one validation rule (`multiple-errors/`
    breaks several at once).
  - `reduced/` drops LGA LA/FBA and ward LA/FAA/W02 (missing-record handling).
  - `renamed/` corrects the name of LGA LA/FAA (updates).
  - `swapped/` swaps the names of LGAs LA/FAA and LA/FBA (deferred uniqueness).
  - `name-conflict/` replaces LA/FBA with LA/FBZ under the same name, which
    clashes with LA/FBA still in the database.
