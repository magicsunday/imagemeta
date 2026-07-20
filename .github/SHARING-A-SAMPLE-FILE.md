# Sharing a sample file

A parser bug is usually easiest to fix with the file that triggers it. That file
is also the problem: this library exists to read metadata, and metadata is where
personal data lives. A GitHub issue is public, indexed, and effectively
permanent — deleting a comment does not retract an attachment.

This page is the long version. The issue templates link here so they can stay
short.

## The short version

1. If a crafted file makes the parser hang, exhaust memory, loop forever, read
   outside its buffer, return data it should not, or take absurdly long, do not
   open a public issue at all. Use the
   [security advisory form](https://github.com/magicsunday/imagemeta/security/advisories/new).
2. Prefer sending the failing structure — a hex window — over the whole file.
3. If you do attach a file, only attach one that is yours to license, that shows
   and names no other identifiable person, and that you accept becoming a
   permanent public test fixture under this repository's licence. Owning the
   copyright in a photograph does not give you authority to publish a third
   party's name, face or whereabouts.
4. If none of that is workable, say so in the issue and ask for a private
   channel. An unreproducible report is better than a disclosure you cannot take
   back.

## Do not strip the metadata

The obvious move — `exiftool -all=`, or a phone's "remove location" toggle — is
the wrong one here. Deleting tags rewrites the offsets, box sizes and index
tables that a parser defect usually lives in. The stripped file no longer
reproduces the bug, and you have filed a report nobody can act on.

Overwrite the **values in place** instead, leaving every offset where it is. A
hex editor works; so does `xxd` for locating and patching, or `bbe` if you give
it a replacement of exactly the same length.

Some of what has to go is not text at all. The embedded thumbnail, MPF
secondary images, an embedded audio stream and a depth map are kilobytes of
binary with nothing to type over. Each is addressed by an offset and a length —
`ThumbnailOffset`/`ThumbnailLength` for the EXIF thumbnail, the MPF entry's
offset and size, the APP2 segment span — so locate the payload and **zero-fill
the whole region, keeping its byte count**. If zero-filling makes the defect
disappear, the defect is *in* that payload: send the failing structure instead.

**Match the byte count, not the character count.** XMP is UTF-8, the Windows XP
tags are UTF-16LE, and `UserComment` may carry a BOM-tagged UTF-16 payload — so
one character is often two bytes, and `José` → `XXXX` shortens the field. In an
ExtendedXMP chain a single missing byte turns your reproducing file into a
different error.

## What to overwrite

This library extracts more than the fields people expect. At minimum:

- GPS coordinates, and the QuickTime and DJI location channels below.
- Camera and lens serial numbers; owner, artist and copyright names.
- IPTC creator contact details — e-mail, phone, street address.
- Keyword and hierarchical-subject trees, which in practice read
  `People/<real name>` and `Places/<home town>`.
- Face regions, including the person names and the per-face identifiers that
  correlate the same individual across every photo you publish.
- The embedded EXIF thumbnail, which is frequently the **uncropped** original —
  cropping someone out of the visible image does not remove them from it.
- MPF secondary images: a panorama frame, a large thumbnail, or an *Original
  Preservation Image*, which is the un-edited original before any edit.
- Any embedded audio stream. A "sound and shot" JPEG carries seconds of recorded
  audio in an APP2 segment, and no visual or hex inspection will notice it.
- Depth maps, and live-photo or burst identifiers that link this file to the
  rest of your library.

**Clearing the EXIF GPS tags is not sufficient.** This library also recovers
location from the QuickTime `location.ISO6709` atom and from DJI telemetry
embedded in the video payload itself, neither of which is touched by clearing
the GPS IFD.

## Where overwriting in place does not work

- **A file carrying a C2PA manifest** — JPEG `APP11`, or a `jumb` box in HEIC,
  AVIF, MP4, MOV or JXL — keeps a *second copy* of the metadata inside the
  manifest, alongside the signing certificate. Overwriting the primary copy
  leaves that one intact.
- **JPEG XL may store EXIF and XMP Brotli-compressed** in a `brob` box. This
  library never decompresses one, so if your JXL fails on *metadata content* it
  is using the uncompressed `Exif` or `xml ` boxes, and you can overwrite those
  normally. Two caveats. A hex dump of a `brob` payload is *compressed, not
  anonymous* — anyone can decompress it. And a malformed `brob` **header** is
  rejected during the container walk, before any payload is read, so a defect
  can sit on a box this library otherwise ignores: in that case send the box
  header chain rather than the payload.

For the C2PA case, send the failing structure rather than the file.

## Sending the failing structure

Usually the most useful thing you can send is not the file but the bytes around
the failure: roughly 256 bytes at the offset the error names, plus the box or
IFD header chain leading to it. It is small enough to read, and it is normally
enough to write a test fixture.

It is **not** anonymous by default. Read its ASCII column first — that catches
a lot, because much of what matters here is stored as text:

- names, file paths and IPTC contact details;
- camera and lens serial numbers, which EXIF stores as ASCII strings;
- XMP, which is UTF-8 throughout — including `exif:GPSLatitude`;
- the QuickTime `location.ISO6709` atom, which reads as `+51.5074-000.1278/`.

An ASCII pass is necessary but **not sufficient**, because the coordinates
themselves have no printable form in two of their sources: the EXIF GPS IFD
stores latitude and longitude as RATIONAL triplets — raw integers — and DJI
telemetry stores them as 64-bit floats inside protobuf. Note the scope: other
tags in that same GPS IFD, such as the N/S reference, the map datum and the date
stamp, *are* ASCII, so seeing readable text near the coordinates proves nothing
about the coordinates.

So also check *which structure* the window covers. If it overlaps the GPS IFD, a
QuickTime location atom, or `mdat` bytes near a `DJI` marker, either zero those
bytes as well or simply name the structure in the issue and let a maintainer ask
for a narrower window.

## Attachment limits

GitHub accepts only a fixed set of file extensions, and most of the containers
this library reads — HEIC, HEIF, AVIF, JXL, AVI, `.m4v`, `.3gp` and the raw
camera formats — are not among them. Rather than guess: if the upload is
rejected, put the file in a `.zip`, which is accepted.

Size is capped at 10 MB for images and 25 MB for everything else, a `.zip`
included. A video is capped at 10 MB unless both this repository's owner is on a
paid plan and you are either on one yourself or a member or collaborator here —
so plan for 10 MB.

**25 MB is the ceiling for every route.** If the smallest file that still
reproduces the defect does not fit, do not look for a file host — send the
failing structure instead.
