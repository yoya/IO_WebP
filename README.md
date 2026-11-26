IO_WebP
=======

English version: [README.en.md](README.en.md)

WebP (RIFF コンテナ) バイナリのチャンク構造を解釈・ダンプする為のライブラリです。IO_Bit が必要です。
チャンクの一覧と、VP8 (非可逆)・VP8L (可逆)・VP8X (拡張)・ALPH (アルファ) のヘッダの値を読めます。
画像のデコードや WebP の書き出し (build) はしません。
利用に制限はかけません。コピーも改変もご自由にどうぞ。MIT ライセンスにしました。
自分の環境だと動かないとか、機能が欲しいとかいった要望があれば連絡下さい。

# License

MIT License

# Install

```
% composer require yoya/io_webp
```

# Usage

- webpdump.php (チャンク構造のダンプ)

```
% php vendor/yoya/io_webp/sample/webpdump.php
Usage: php webpdump.php [-h] -f <webpfile>
% php vendor/yoya/io_webp/sample/webpdump.php -f input.webp
FourCC:RIFF Size:1610 Signature:WEBP
Header:VP8  TagSize:1598
    key_frame:0 version:0 show_frame:1 first_part_size:942
    width:640 horizontal_scale:0 height:480 vertical_scale:0
% php vendor/yoya/io_webp/sample/webpdump.php -f alpha.webp
FourCC:RIFF Size:982 Signature:WEBP
Header:VP8X TagSize:10
    I(icc):0 L(alpha):1 E(exif):0 X(xmp):0 A(anim):0
    image_width:640 image_height:480
Header:ALPH TagSize:202
    P(process):0 F(filter):0 C(compress):1
    (alpha bitstream)
Header:VP8  TagSize:742
    key_frame:0 version:0 show_frame:1 first_part_size:587
    width:640 horizontal_scale:0 height:480 vertical_scale:0
```

- PHP から使う

```php
require_once 'IO/WebP.php';

$webp = new IO_WebP();
$webp->parse(file_get_contents('alpha.webp'));
foreach ($webp->ChunkList as $chunk) {
    echo $chunk['Header'], ' ', $chunk['TagSize'], PHP_EOL;  // "VP8X 10", "ALPH 202", "VP8  742"
}
$vp8x = $webp->ChunkList[0];
echo $vp8x['image_width'], 'x', $vp8x['image_height'], ' alpha:', $vp8x['L'], PHP_EOL;  // 640x480 alpha:1
```

# 設計概要

| クラス | 役割 |
|---|---|
| `IO_WebP` | WebP 全体。`parse` / `dump` |
| `IO_Bit` (別パッケージ) | バイト・ビット単位の読み書き |

- `parse()` は RIFF ヘッダを `FileHeader` (`FourCC`, `Size`, `Signature`) に、各チャンクを `ChunkList` に並べる。
  チャンクは `Header` (FourCC) と `TagSize` を持ち、次の種類はヘッダの値も持つ。チャンクの中身のバイト列は保持しない。
  - `VP8 `: `key_frame` (フレーム種別のビットそのまま。0 がキーフレーム) `version` `show_frame` `first_part_size` `width` `height` `horizontal_scale` `vertical_scale`
  - `VP8L`: `signature` (0x2f。違えば STDERR に警告を出す) `image_width` `image_height` `alpha_is_used` `version_number`
  - `VP8X`: フラグ `I` (ICC) `L` (アルファ) `E` (Exif) `X` (XMP) `A` (アニメーション) と、キャンバスの `image_width` `image_height`
  - `ALPH`: `P` (前処理) `F` (フィルタ) `C` (圧縮)
- 奇数長のチャンクの後のパディング 1 バイトは読み飛ばす。
- その他のチャンク (ICCP, EXIF, XMP, ANIM, ANMF など) は `Header` と `TagSize` だけ。

# 既知の制限

- ANMF (アニメーションのフレーム) の中のチャンクには入らない。
- `webpdump.php` の `-h` は何もしない (`dump()` がオプションを見ていない)。

# Test

```
% php tests/run.php
```

IO_Bit は vendor/ (composer install) か include_path から読み込みます。

# Reference

- https://developers.google.com/speed/webp/docs/riff_container
- https://developers.google.com/speed/webp/docs/webp_lossless_bitstream_specification
- https://datatracker.ietf.org/doc/html/rfc6386 (VP8)
