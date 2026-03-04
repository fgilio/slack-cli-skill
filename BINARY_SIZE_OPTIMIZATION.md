# Binary Size Optimization Research

## Current State

| Component | Size |
|-----------|------|
| Original binary (from Git LFS) | **34.6 MB** |
| micro.sfx (PHP runtime) | ~26.7 MB (estimated) |
| PHAR (application + vendor) | ~7.9 MB (GZ compressed from ~29 MB) |

## Where the Size Comes From

The self-contained binary = `micro.sfx` (static PHP runtime) + `slack-cli.phar` (compressed app).

### micro.sfx Breakdown

The PHP runtime is by far the biggest component. Its size depends entirely on which
PHP extensions are compiled in, because each extension pulls in C libraries that get
statically linked.

**Measured library sizes (static `.a` archives):**

| Library | Size | Required by | Needed? |
|---------|------|------------|---------|
| libicudata.a | **30.4 MB** | `intl` extension | NO |
| libicui18n.a | **11.2 MB** | `intl` extension | NO |
| libcrypto.a | **10.7 MB** | `openssl` extension | YES (HTTPS) |
| libicuuc.a | **5.4 MB** | `intl` extension | NO |
| libxml2.a | 2.1 MB | `dom`, `xml`, `simplexml` | NO |
| libssl.a | 1.8 MB | `openssl` extension | YES (HTTPS) |
| libcurl.a | 1.7 MB | `curl` extension | YES (HTTP client) |
| libgmp.a | 1.5 MB | `gmp` extension | NO |
| libsqlite3.a | 1.4 MB | `sqlite3`, `pdo_sqlite` | NO |
| libiconv.a | 1.1 MB | `iconv`, `libxml2` | Only if XML needed |
| libonig.a | 0.8 MB | `mbregex` (mb_ereg*) | NO |
| libsodium.a | 0.6 MB | `sodium` extension | NO |
| libz.a | 0.1 MB | `zlib` extension | YES (compression) |

**Key finding: ICU (used by `intl`) alone accounts for 47 MB of static libraries.**
The app does not use `intl` — it's pulled in by Symfony polyfills which provide
fallback PHP implementations when the extension is absent.

### PHAR Breakdown

84 production packages, 29 MB uncompressed → 7.9 MB GZ compressed.

**Top packages by size:**

| Package | Size | Needed? |
|---------|------|---------|
| jolicode/jolinotif | **6.8 MB** (6.3 MB are Windows `.exe` files) | Framework dep, not used by this app |
| laravel-zero/framework | 3.1 MB | YES (framework) |
| nesbot/carbon | 2.7 MB (1.2 MB locale files) | Transitive dep via illuminate/support |
| laravel-zero/foundation | 2.4 MB | YES (framework) |
| symfony/* (various) | ~5.0 MB total | Mostly framework deps |
| illuminate/* (various) | ~3.0 MB total | Framework core |
| voku/portable-ascii | 0.6 MB | Transitive dep |

## Optimization Results (Measured on Linux x86_64)

### Build 1: Medium extension set (25 extensions, includes intl/xml/sodium)

```
micro.sfx:     58.3 MB
Final binary:  ~66 MB
```

### Build 2: Minimal extension set (8 extensions)

Extensions: `ctype, curl, filter, mbstring, openssl, phar, tokenizer, zlib`

```
micro.sfx:     11.1 MB
Final binary:  19.0 MB
```

### Build 3: Minimal + UPX compression (Linux only)

```
micro.sfx:      4.2 MB (62% compression)
Final binary:  12.1 MB
```

### Summary

| Build | micro.sfx | Final Binary | vs. Original |
|-------|-----------|-------------|-------------|
| Original | ~26.7 MB | 34.6 MB | baseline |
| Minimal extensions | 11.1 MB | 19.0 MB | **-45%** |
| Minimal + UPX (Linux) | 4.2 MB | 12.1 MB | **-65%** |

## Recommended Minimal Extension Set

These 8 extensions are all this Slack CLI tool needs:

| Extension | Why | C Libraries |
|-----------|-----|-------------|
| `ctype` | Laravel framework requirement | none |
| `curl` | HTTP requests to Slack API | libcurl, openssl, zlib |
| `filter` | Input filtering (Laravel) | none |
| `mbstring` | String handling (Laravel, Symfony) | none (without mbregex) |
| `openssl` | TLS/HTTPS | openssl |
| `phar` | Self-contained binary loading | none |
| `tokenizer` | Laravel framework | none |
| `zlib` | PHAR GZ compression, HTTP compression | zlib |

**Extensions NOT needed** (confirmed by code analysis):

| Extension | Why NOT needed | Size saved |
|-----------|---------------|------------|
| `intl` | App doesn't use Intl functions; Symfony has PHP polyfills | **~47 MB** |
| `dom`, `xml`, `simplexml`, `xmlreader`, `xmlwriter` | App doesn't process XML | **~3 MB** |
| `iconv` | Only needed by libxml2 | **~1 MB** |
| `sodium` | App doesn't use encryption | **~0.6 MB** |
| `mbregex` | App doesn't use mb_ereg* functions | **~0.8 MB** |
| `gd`, `gmp`, `sqlite3`, `pdo_*`, `mysqli`, `mysqlnd` | No image/math/database operations | **varies** |
| `pcntl`, `posix`, `sockets` | No process/signal/socket operations | small |
| `session`, `fileinfo`, `exif`, `readline`, `ftp` | Not used | small |
| `opcache` | Not beneficial for single-run CLI scripts in PHAR | small |

## Build Commands

### Minimal build (recommended)

```bash
# Download sources (one-time)
spc download --with-php=8.3 \
  --for-extensions="ctype,curl,filter,mbstring,openssl,phar,tokenizer,zlib" \
  --prefer-pre-built

# Build micro.sfx
spc build "ctype,curl,filter,mbstring,openssl,phar,tokenizer,zlib" --build-micro

# On Linux: add UPX compression
spc install-pkg upx
spc build "ctype,curl,filter,mbstring,openssl,phar,tokenizer,zlib" \
  --build-micro --with-upx-pack
```

### Then build the final binary

```bash
cd src && ./slack-cli build
```

## Platform-Specific Notes

### macOS

- macOS cannot produce fully static binaries — `libSystem.B.dylib` and `libresolv` are
  always dynamically linked. This is an Apple limitation, not a PHP one.
- **UPX does NOT work on macOS binaries.** The 62% micro.sfx compression from UPX is
  Linux-only. On macOS, the minimal binary will be ~19 MB (not ~12 MB).
- macOS ships LibreSSL (not OpenSSL). Using system SSL would create compatibility risks
  and is not recommended. Bundling OpenSSL (~12.5 MB) is unavoidable for HTTPS.
- Gatekeeper approval is already handled by the build script.

### Linux

- UPX compression works and provides ~62% reduction of micro.sfx.
- Builds use musl libc for full static linking — no glibc dependency.
- The binary runs on any Linux x86_64 system without any shared libraries.

## Future Optimizations (Not Yet Implemented)

### 1. Compiler flags (LTO, -Oz) — Estimated 10-20% further reduction

Create `config/env.custom.ini` for SPC:
```ini
[global]
SPC_DEFAULT_C_FLAGS="-fPIC -Oz -flto -ffunction-sections -fdata-sections"

[linux]
SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_PROGRAM="-flto -Wl,--gc-sections"

[macos]
SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_PROGRAM="-flto -Wl,-dead_strip"
```

`-Oz` optimizes aggressively for size (clang only). `-flto` enables Link-Time
Optimization which eliminates unused code across translation units. `--gc-sections`
removes unreferenced sections from the final binary.

### 2. PHAR size reduction — Estimated 1-2 MB savings

The PHAR contains unnecessary files that get compressed but still contribute size:

- `jolicode/jolinotif/bin/` — 6.3 MB of Windows `.exe` files (useless on macOS/Linux)
- `nesbot/carbon/src/Carbon/Lang/` — 1.2 MB of locale files (this app only uses `en`)
- Various `README.md`, `CHANGELOG.md`, `LICENSE`, test files

These can be excluded via `box.json` configuration or by removing the packages.

### 3. Remove unnecessary vendor packages — Estimated 3-5 MB PHAR savings

The Laravel Zero framework pulls in packages this CLI tool doesn't need:

| Package | Size | Pulled by | Alternative |
|---------|------|-----------|-------------|
| jolicode/jolinotif | 6.8 MB | laravel-desktop-notifier | Fork framework without it |
| nesbot/carbon | 2.7 MB | illuminate/support | Can't easily remove |
| filp/whoops | 0.3 MB | nunomaduro/collision | Error handling (useful in dev) |
| symfony/http-kernel | 0.7 MB | illuminate/http | Would need custom framework build |

### 4. Consider alternatives to Laravel Zero

For maximum size reduction, a lighter framework or no framework could dramatically
reduce the PHAR. The app is ~10 commands with a single HTTP service. A framework-less
approach using just Symfony Console + Guzzle would reduce vendor from 84 to ~15 packages.

Estimated final binary: **~10-14 MB** on macOS, **~6-8 MB** on Linux with UPX.
