# Building and signing releases

## The release key

Releases are signed with an Ed25519 SSH key (`ssh-keygen -Y sign`, namespace
`buffalo-release`). The private key **never** enters the repository, CI logs
or the release itself. The public key is committed as
`security/release-signing.pub` (allowed-signers format) and embedded in
`install.sh`. `make release` refuses to build if the two differ.

> **The key currently committed is a development TEST key**
> (principal `buffalo-release-test`). Installers refuse releases signed with
> it unless `--allow-test-signature` is given. Before the first production
> release, the project owner must create the real key and replace it.

Creating the real key (once, on the offline signing machine, by the owner):

    scripts/ops/release-key.sh /secure/offline/buffalo-release-key

It asks for a passphrase and prints the line to commit as
`security/release-signing.pub` (principal `buffalo-release`, replacing the
test line; also paste it into `ALLOWED_SIGNERS` in `scripts/ops/install.sh`)
and the fingerprint to publish to installers through a separate channel.
Back the private key up with the other escrowed keys.

### Rotating the key

`upgrade.sh` verifies a new release with the key of the **installed**
release, so trust carries forward. To rotate: publish release N signed with
the old key that contains both keys in `security/release-signing.pub`; sign
release N+1 with the new key; remove the old key in a later release.
Installers of new systems check the new fingerprint as usual.

## Building

On the build machine (Docker with buildx, zstd, git, ssh-keygen):

    make release VERSION=1.2.3 RELEASE_KEY=/secure/offline/buffalo-release-key/buffalo-release

- Builds every image for `linux/amd64` without cache (`scripts/build-release-images.sh`;
  the patched third-party images first, then BUFFALO's images on top).
- Records each image's ID in `images.lock` inside the bundle.
- Writes `RELEASE-NOTES.md` from the Conventional Commit subjects
  (`feat`, `fix`, `build`, `ci`, `docs`) since the previous tag (`SINCE=` to
  override) and lists new migrations.
- Saves the images, packs the bundle, writes `SHA256SUMS` and signs it, then
  verifies the signature against the committed public key.

Output: `dist/buffalo-1.2.3/`. Tag the commit `v1.2.3` after publishing.
Run the Trivy gate (`scripts/security-scan.sh trivy`) on the same commit
before releasing.

## Registries

Connected and cloud installations may pull images from a registry instead
of the images archive. Push the images under the release version
(`<registry>/<name>:<version>`). The bundle and `SHA256SUMS(.sig)` are still
required: `install.sh --registry` and `upgrade.sh --registry` pull each image
and compare its image ID with the signed `images.lock`, refusing any image
that differs.
