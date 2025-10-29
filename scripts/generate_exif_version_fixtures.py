#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import struct
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable, List, Optional, Sequence, Tuple

TYPE_SIZES = {
    1: 1,  # BYTE
    2: 1,  # ASCII
    3: 2,  # SHORT
    4: 4,  # LONG
    5: 8,  # RATIONAL
    7: 1,  # UNDEFINED
    9: 4,  # SLONG
    10: 8,  # SRATIONAL
}

@dataclass
class DataBlock:
    data: bytes
    align: int = 1
    offset: Optional[int] = None

@dataclass
class IfdEntry:
    tag: int
    type: int
    count: int
    value_bytes: Optional[bytes] = None
    data_block: Optional[DataBlock] = None
    child_ifd: Optional["Ifd"] = None
    points_to: Optional[DataBlock] = None

    def inline_bytes(self) -> bytes:
        if self.value_bytes is None:
            raise ValueError("No inline bytes available")
        return self.value_bytes + b"\x00" * (4 - len(self.value_bytes))

@dataclass
class Ifd:
    entries: List[IfdEntry] = field(default_factory=list)
    next_ifd: Optional["Ifd"] = None
    offset: Optional[int] = None

    def body_size(self) -> int:
        return 2 + len(self.entries) * 12 + 4

class ExifBuilder:
    def __init__(self) -> None:
        self.data_blocks: List[DataBlock] = []

    def new_ifd(self) -> Ifd:
        return Ifd()

    def create_data_block(self, data: bytes, align: int = 1) -> DataBlock:
        block = DataBlock(data=data, align=align)
        self.data_blocks.append(block)
        return block

    def add_entry(
        self,
        ifd: Ifd,
        tag: int,
        type_id: int,
        values: Iterable[int] | bytes | str | Tuple[int, int] | Sequence[Tuple[int, int]] | None,
        *,
        count: Optional[int] = None,
        align: Optional[int] = None,
        child_ifd: Optional[Ifd] = None,
        points_to: Optional[DataBlock] = None,
    ) -> IfdEntry:
        data: bytes
        if type_id == 2:
            assert isinstance(values, (str, bytes))
            data = values.encode("ascii") if isinstance(values, str) else values
            if not data.endswith(b"\x00"):
                data += b"\x00"
            actual_count = len(data)
        elif type_id in (1, 7):
            assert isinstance(values, (bytes, bytearray))
            data = bytes(values)
            actual_count = len(data) if count is None else count
        elif type_id == 3:
            assert isinstance(values, Iterable)
            packed = bytearray()
            actual_count = 0
            for v in values:
                packed += struct.pack("<H", int(v))
                actual_count += 1
            data = bytes(packed)
        elif type_id in (4, 9):
            assert isinstance(values, Iterable)
            packed = bytearray()
            actual_count = 0
            fmt = "<I" if type_id == 4 else "<i"
            for v in values:
                packed += struct.pack(fmt, int(v))
                actual_count += 1
            data = bytes(packed)
        elif type_id == 5:
            assert isinstance(values, Iterable)
            packed = bytearray()
            actual_count = 0
            for num, den in values:
                packed += struct.pack("<II", int(num), int(den))
                actual_count += 1
            data = bytes(packed)
        elif type_id == 10:
            assert isinstance(values, Iterable)
            packed = bytearray()
            actual_count = 0
            for num, den in values:
                packed += struct.pack("<ii", int(num), int(den))
                actual_count += 1
            data = bytes(packed)
        else:
            raise ValueError(f"Unsupported type {type_id}")

        if count is not None:
            actual_count = count

        entry = IfdEntry(tag=tag, type=type_id, count=actual_count)
        unit_size = TYPE_SIZES[type_id]
        total_size = unit_size * actual_count

        if child_ifd is not None:
            entry.child_ifd = child_ifd
            entry.value_bytes = b"\x00" * 4
        elif points_to is not None:
            entry.points_to = points_to
            entry.value_bytes = b"\x00" * 4
        elif total_size <= 4:
            entry.value_bytes = data + b"\x00" * (4 - total_size)
        else:
            block = self.create_data_block(data, align or self._default_alignment(type_id))
            entry.data_block = block

        ifd.entries.append(entry)
        return entry

    def _default_alignment(self, type_id: int) -> int:
        if type_id in (4, 5, 9, 10):
            return 4
        if type_id == 3:
            return 2
        return 1

    def build(self, root: Ifd) -> bytes:
        ifds: List[Ifd] = []
        seen: set[int] = set()

        def collect(ifd: Ifd) -> None:
            if id(ifd) in seen:
                return
            seen.add(id(ifd))
            ifds.append(ifd)
            for entry in ifd.entries:
                if entry.child_ifd is not None:
                    collect(entry.child_ifd)
            if ifd.next_ifd is not None:
                collect(ifd.next_ifd)

        collect(root)

        offset = 8
        for ifd in ifds:
            ifd.offset = offset
            offset += ifd.body_size()

        data_offset = offset
        for block in self.data_blocks:
            pad = (block.align - (data_offset % block.align)) % block.align
            data_offset += pad
            block.offset = data_offset
            data_offset += len(block.data)

        total_length = data_offset
        buf = bytearray(total_length)
        buf[0:2] = b"II"
        buf[2:4] = struct.pack("<H", 42)
        buf[4:8] = struct.pack("<I", root.offset)

        for ifd in ifds:
            pos = ifd.offset
            entries = sorted(ifd.entries, key=lambda e: e.tag)
            buf[pos:pos+2] = struct.pack("<H", len(entries))
            pos += 2
            for entry in entries:
                buf[pos:pos+2] = struct.pack("<H", entry.tag)
                buf[pos+2:pos+4] = struct.pack("<H", entry.type)
                buf[pos+4:pos+8] = struct.pack("<I", entry.count)
                if entry.child_ifd is not None:
                    buf[pos+8:pos+12] = struct.pack("<I", entry.child_ifd.offset)
                elif entry.points_to is not None:
                    if entry.points_to.offset is None:
                        raise ValueError("Data block offset unresolved")
                    buf[pos+8:pos+12] = struct.pack("<I", entry.points_to.offset)
                elif entry.data_block is not None:
                    if entry.data_block.offset is None:
                        raise ValueError("Data block offset unresolved")
                    buf[pos+8:pos+12] = struct.pack("<I", entry.data_block.offset)
                else:
                    buf[pos+8:pos+12] = entry.inline_bytes()
                pos += 12
            buf[pos:pos+4] = struct.pack("<I", ifd.next_ifd.offset if ifd.next_ifd else 0)

        for block in self.data_blocks:
            if block.offset is None:
                raise ValueError("Data block offset unresolved")
            buf[block.offset:block.offset+len(block.data)] = block.data

        return b"Exif\x00\x00" + bytes(buf)

FIXTURE_DIR = Path(__file__).resolve().parents[1] / "tests" / "Fixtures" / "Images" / "ExifVersions"

PREVIEW_PAYLOAD = bytes.fromhex(
    "ffd8ffe000104a46494600010100000100010000ffdb004300"
    "0302020302020303030304030508040505050a070706080c0a"
    "0c0c0b0a0b0b0d0e12100d0e110b0b10161011131415151616"
    "0c0f17181715141415341f1f182c22263437393a3a161f3c40"
    "3c38403a39383a"
    "ffd9"
)
THUMBNAIL_PAYLOAD = b"\xff\xd8THUMBNAIL_FIXTURE\xff\xd9"


def user_comment_payload(text: str) -> bytes:
    return b"ASCII\0\0\0" + text.encode("ascii") + b"\0"


def spatial_frequency_payload(columns: List[str], rows: List[str], values: List[List[Tuple[int, int]]]) -> bytes:
    if len(columns) == 0 or len(rows) == 0:
        raise ValueError("SFR payload requires at least one column and row")
    if len(values) != len(rows):
        raise ValueError("Row value count mismatch")
    for row_vals in values:
        if len(row_vals) != len(columns):
            raise ValueError("Column value count mismatch")

    payload = bytearray(struct.pack(">HH", len(columns), len(rows)))
    for label in columns:
        payload.extend(label.encode("ascii"))
        payload.append(0)
    for label in rows:
        payload.extend(label.encode("ascii"))
        payload.append(0)
    for row_vals in values:
        for num, den in row_vals:
            payload.extend(struct.pack(">ii", num, den))
    return bytes(payload)


@dataclass
class FixtureConfig:
    filename: str
    exif_version: str
    iso: int
    datetime_original: str
    datetime_digitized: str
    offset_time: str
    subsec_original: str
    user_comment: str
    maker_note: bytes
    maker_note_safe: bool
    interop_file_format: str
    interop_width: int
    interop_length: int
    preview_width: int
    preview_height: int
    preview_bit_depth: int
    preview_compression: int
    preview_color_space: int
    preview_scale: Tuple[int, int]
    preview_encoding: str
    preview_mime: str
    tiff_ep_id: Optional[List[int]] = None
    make: str = "MagicSunday"
    model: str = "ImageMeta Fixture"
    software: str = "FixtureGenerator 1.0"
    image_width: int = 4000
    image_height: int = 3000
    focal_length: Tuple[int, int] = (35, 1)
    subject_distance: Tuple[int, int] = (5, 1)
    subject_distance_range: int = 2
    temperature: Optional[Tuple[int, int]] = None
    humidity: Optional[Tuple[int, int]] = None
    pressure: Optional[Tuple[int, int]] = None
    spatial_frequency: Optional[bytes] = None


FIXTURES: List[FixtureConfig] = [
    FixtureConfig(
        filename="exif-1-0.jpg",
        exif_version="0100",
        iso=100,
        datetime_original="2020:01:02 03:04:05",
        datetime_digitized="2020:01:02 03:04:05",
        offset_time="+00:00",
        subsec_original="123",
        user_comment="Legacy 1.0 comment",
        maker_note=b"MSIF\x00fixture-1.0",
        maker_note_safe=False,
        interop_file_format="JPEG",
        interop_width=4000,
        interop_length=3000,
        preview_width=1600,
        preview_height=900,
        preview_bit_depth=8,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(1, 2),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
    ),
    FixtureConfig(
        filename="exif-1-1.jpg",
        exif_version="0110",
        iso=110,
        datetime_original="2021:02:03 04:05:06",
        datetime_digitized="2021:02:03 04:05:06",
        offset_time="+01:00",
        subsec_original="234",
        user_comment="Legacy 1.1 comment",
        maker_note=b"MSIF\x00fixture-1.1",
        maker_note_safe=False,
        interop_file_format="JPEG",
        interop_width=4200,
        interop_length=3100,
        preview_width=1700,
        preview_height=950,
        preview_bit_depth=8,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(3, 5),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
    ),
    FixtureConfig(
        filename="exif-2-1.jpg",
        exif_version="0210",
        iso=160,
        datetime_original="2022:03:04 05:06:07",
        datetime_digitized="2022:03:04 05:06:07",
        offset_time="+02:00",
        subsec_original="345",
        user_comment="Exif 2.1 comment",
        maker_note=b"MSIF\x00fixture-2.1",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=4300,
        interop_length=3200,
        preview_width=1800,
        preview_height=960,
        preview_bit_depth=10,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(2, 3),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
    ),
    FixtureConfig(
        filename="exif-2-2.jpg",
        exif_version="0220",
        iso=180,
        datetime_original="2023:04:05 06:07:08",
        datetime_digitized="2023:04:05 06:07:08",
        offset_time="+02:00",
        subsec_original="456",
        user_comment="Exif 2.2 comment",
        maker_note=b"MSIF\x00fixture-2.2",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=4400,
        interop_length=3300,
        preview_width=1920,
        preview_height=1080,
        preview_bit_depth=10,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(1, 2),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
    ),
    FixtureConfig(
        filename="exif-2-21.jpg",
        exif_version="0221",
        iso=190,
        datetime_original="2024:05:06 07:08:09",
        datetime_digitized="2024:05:06 07:08:09",
        offset_time="+02:00",
        subsec_original="567",
        user_comment="Exif 2.21 comment",
        maker_note=b"MSIF\x00fixture-2.21",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=4500,
        interop_length=3400,
        preview_width=2048,
        preview_height=1152,
        preview_bit_depth=10,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(2, 5),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
    ),
    FixtureConfig(
        filename="exif-2-3.jpg",
        exif_version="0230",
        iso=210,
        datetime_original="2025:06:07 08:09:10",
        datetime_digitized="2025:06:07 08:09:10",
        offset_time="+02:00",
        subsec_original="678",
        user_comment="Exif 2.3 comment",
        maker_note=b"MSIF\x00fixture-2.3",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=4600,
        interop_length=3450,
        preview_width=2200,
        preview_height=1230,
        preview_bit_depth=12,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(3, 7),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
        tiff_ep_id=[2, 0, 0, 1],
    ),
    FixtureConfig(
        filename="exif-2-31.jpg",
        exif_version="0231",
        iso=220,
        datetime_original="2026:07:08 09:10:11",
        datetime_digitized="2026:07:08 09:10:11",
        offset_time="+02:00",
        subsec_original="789",
        user_comment="Exif 2.31 comment",
        maker_note=b"MSIF\x00fixture-2.31",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=4700,
        interop_length=3500,
        preview_width=2300,
        preview_height=1290,
        preview_bit_depth=12,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(4, 9),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
        tiff_ep_id=[2, 0, 0, 2],
    ),
    FixtureConfig(
        filename="exif-2-32.jpg",
        exif_version="0232",
        iso=230,
        datetime_original="2027:08:09 10:11:12",
        datetime_digitized="2027:08:09 10:11:12",
        offset_time="+02:00",
        subsec_original="890",
        user_comment="Exif 2.32 comment",
        maker_note=b"MSIF\x00fixture-2.32",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=4800,
        interop_length=3600,
        preview_width=2400,
        preview_height=1350,
        preview_bit_depth=12,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(1, 3),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
        tiff_ep_id=[2, 0, 0, 3],
        temperature=(225, 10),
        humidity=(45, 1),
        pressure=(101325, 100),
        spatial_frequency=spatial_frequency_payload(
            ["Red", "Green"],
            ["10 lp/mm", "20 lp/mm"],
            [
                [(80, 100), (75, 100)],
                [(60, 100), (55, 100)],
            ],
        ),
    ),
    FixtureConfig(
        filename="exif-3-0.jpg",
        exif_version="0300",
        iso=300,
        datetime_original="2028:09:10 11:12:13",
        datetime_digitized="2028:09:10 11:12:13",
        offset_time="+00:00",
        subsec_original="901",
        user_comment="Preview 3.0 comment",
        maker_note=b"MSIF\x00fixture-3.0",
        maker_note_safe=True,
        interop_file_format="JPEG",
        interop_width=5000,
        interop_length=3700,
        preview_width=2560,
        preview_height=1440,
        preview_bit_depth=12,
        preview_compression=6,
        preview_color_space=1,
        preview_scale=(1, 2),
        preview_encoding="JPEG",
        preview_mime="image/jpeg",
        tiff_ep_id=[0x30, 0x31, 0x30, 0x30],
        temperature=(180, 10),
        humidity=(50, 1),
        pressure=(100800, 100),
        spatial_frequency=spatial_frequency_payload(
            ["R", "G", "B"],
            ["5 lp/mm", "10 lp/mm"],
            [
                [(90, 100), (88, 100), (85, 100)],
                [(70, 100), (68, 100), (65, 100)],
            ],
        ),
    ),
]


def build_fixture(config: FixtureConfig) -> None:
    builder = ExifBuilder()
    ifd0 = builder.new_ifd()
    exif_ifd = builder.new_ifd()
    gps_ifd = builder.new_ifd()
    interop_ifd = builder.new_ifd()
    ifd1 = builder.new_ifd()
    ifd0.next_ifd = ifd1

    preview_block = builder.create_data_block(PREVIEW_PAYLOAD)
    thumbnail_block = builder.create_data_block(THUMBNAIL_PAYLOAD)

    # Primary IFD entries
    builder.add_entry(ifd0, 0x0100, 4, [config.image_width])
    builder.add_entry(ifd0, 0x0101, 4, [config.image_height])
    builder.add_entry(ifd0, 0x010F, 2, config.make)
    builder.add_entry(ifd0, 0x0110, 2, config.model)
    builder.add_entry(ifd0, 0x0112, 3, [1])
    builder.add_entry(ifd0, 0x011A, 5, [ (300, 1) ])
    builder.add_entry(ifd0, 0x011B, 5, [ (300, 1) ])
    builder.add_entry(ifd0, 0x0128, 3, [2])
    builder.add_entry(ifd0, 0x0131, 2, config.software)
    builder.add_entry(ifd0, 0x0132, 2, config.datetime_digitized)
    builder.add_entry(ifd0, 0x013B, 2, "Integration Test")
    builder.add_entry(ifd0, 0x8769, 4, [0], child_ifd=exif_ifd)
    builder.add_entry(ifd0, 0x8825, 4, [0], child_ifd=gps_ifd)

    # Thumbnail IFD entries
    builder.add_entry(ifd1, 0x0103, 3, [6])
    builder.add_entry(ifd1, 0x0100, 4, [320])
    builder.add_entry(ifd1, 0x0101, 4, [240])
    builder.add_entry(ifd1, 0x0201, 4, [0], points_to=thumbnail_block)
    builder.add_entry(ifd1, 0x0202, 4, [len(THUMBNAIL_PAYLOAD)])

    # EXIF IFD entries
    builder.add_entry(exif_ifd, 0x829A, 5, [ (1, 125) ])
    builder.add_entry(exif_ifd, 0x829D, 5, [ (56, 10) ])
    builder.add_entry(exif_ifd, 0x8822, 3, [3])
    builder.add_entry(exif_ifd, 0x8827, 3, [config.iso])
    builder.add_entry(exif_ifd, 0x8830, 3, [4])
    builder.add_entry(exif_ifd, 0x9000, 7, config.exif_version.encode("ascii"), count=4)
    builder.add_entry(exif_ifd, 0x9003, 2, config.datetime_original)
    builder.add_entry(exif_ifd, 0x9004, 2, config.datetime_digitized)
    builder.add_entry(exif_ifd, 0x9011, 2, config.offset_time)
    builder.add_entry(exif_ifd, 0x9012, 2, config.offset_time)
    builder.add_entry(exif_ifd, 0x9204, 10, [ (0, 1) ])
    builder.add_entry(exif_ifd, 0x9206, 5, [config.subject_distance])
    builder.add_entry(exif_ifd, 0x9209, 3, [0])
    builder.add_entry(exif_ifd, 0x920A, 5, [config.focal_length])
    builder.add_entry(exif_ifd, 0x9286, 7, user_comment_payload(config.user_comment))
    builder.add_entry(exif_ifd, 0x927C, 7, config.maker_note)
    builder.add_entry(exif_ifd, 0x9291, 2, config.subsec_original)
    builder.add_entry(exif_ifd, 0xA000, 7, b"0100", count=4)
    builder.add_entry(exif_ifd, 0xA001, 3, [1])
    builder.add_entry(exif_ifd, 0xA002, 4, [config.image_width])
    builder.add_entry(exif_ifd, 0xA003, 4, [config.image_height])
    builder.add_entry(exif_ifd, 0xA005, 4, [0], child_ifd=interop_ifd)
    builder.add_entry(exif_ifd, 0xA20E, 5, [ (config.image_width * 100, 1) ])
    builder.add_entry(exif_ifd, 0xA20F, 5, [ (config.image_height * 100, 1) ])
    builder.add_entry(exif_ifd, 0xA210, 3, [2])
    builder.add_entry(exif_ifd, 0xA217, 3, [2])
    builder.add_entry(exif_ifd, 0xA300, 7, b"\x03", count=1)
    builder.add_entry(exif_ifd, 0xA301, 7, b"\x01", count=1)
    builder.add_entry(exif_ifd, 0xA401, 3, [0])
    builder.add_entry(exif_ifd, 0xA402, 3, [0])
    builder.add_entry(exif_ifd, 0xA403, 3, [1])
    builder.add_entry(exif_ifd, 0xA404, 5, [ (config.preview_width, config.image_width) ])
    builder.add_entry(exif_ifd, 0xA405, 3, [52])
    builder.add_entry(exif_ifd, 0xA406, 3, [0])
    builder.add_entry(exif_ifd, 0xA40C, 3, [config.subject_distance_range])
    builder.add_entry(exif_ifd, 0xC51B, 4, [0], points_to=preview_block)
    builder.add_entry(exif_ifd, 0xC51C, 4, [len(PREVIEW_PAYLOAD)])
    builder.add_entry(exif_ifd, 0xC51F, 4, [config.preview_width])
    builder.add_entry(exif_ifd, 0xC520, 4, [config.preview_height])
    builder.add_entry(exif_ifd, 0xC521, 3, [config.preview_color_space])
    builder.add_entry(exif_ifd, 0xC522, 3, [config.preview_bit_depth])
    builder.add_entry(exif_ifd, 0xC525, 3, [config.preview_compression])
    builder.add_entry(exif_ifd, 0xC526, 5, [config.preview_scale])
    builder.add_entry(exif_ifd, 0xC51D, 2, config.preview_encoding)
    builder.add_entry(exif_ifd, 0xC51E, 2, config.preview_mime)
    builder.add_entry(exif_ifd, 0xC635, 3, [1 if config.maker_note_safe else 0])

    if config.tiff_ep_id:
        builder.add_entry(exif_ifd, 0xA216, 1, bytes(config.tiff_ep_id), count=len(config.tiff_ep_id))

    if config.temperature:
        builder.add_entry(exif_ifd, 0x9400, 5, [config.temperature])
    if config.humidity:
        builder.add_entry(exif_ifd, 0x9401, 5, [config.humidity])
    if config.pressure:
        builder.add_entry(exif_ifd, 0x9402, 5, [config.pressure])
    if config.spatial_frequency:
        builder.add_entry(exif_ifd, 0xA20C, 7, config.spatial_frequency)

    # Interoperability IFD
    builder.add_entry(interop_ifd, 0x0001, 2, "R98")
    builder.add_entry(interop_ifd, 0x0002, 2, "0100")
    builder.add_entry(interop_ifd, 0x1000, 2, config.interop_file_format)
    builder.add_entry(interop_ifd, 0x1001, 4, [config.interop_width])
    builder.add_entry(interop_ifd, 0x1002, 4, [config.interop_length])

    # GPS IFD
    builder.add_entry(gps_ifd, 0x0000, 1, bytes([2, 3, 0, 0]))
    builder.add_entry(gps_ifd, 0x0001, 2, "N")
    builder.add_entry(gps_ifd, 0x0002, 5, [(52, 1), (30, 1), (12345, 100)])
    builder.add_entry(gps_ifd, 0x0003, 2, "E")
    builder.add_entry(gps_ifd, 0x0004, 5, [(13, 1), (24, 1), (54321, 100)])
    builder.add_entry(gps_ifd, 0x0005, 1, b"\x00", count=1)
    builder.add_entry(gps_ifd, 0x0006, 5, [(345, 10)])
    builder.add_entry(gps_ifd, 0x0007, 5, [(12, 1), (34, 1), (5678, 100)])
    builder.add_entry(gps_ifd, 0x0008, 2, "5")
    builder.add_entry(gps_ifd, 0x0009, 2, "A")
    builder.add_entry(gps_ifd, 0x000A, 2, "3")
    builder.add_entry(gps_ifd, 0x000B, 5, [(15, 10)])
    builder.add_entry(gps_ifd, 0x000C, 2, "K")
    builder.add_entry(gps_ifd, 0x000D, 5, [(123, 10)])
    builder.add_entry(gps_ifd, 0x000E, 2, "T")
    builder.add_entry(gps_ifd, 0x000F, 5, [(45, 1)])
    builder.add_entry(gps_ifd, 0x0010, 2, "T")
    builder.add_entry(gps_ifd, 0x0011, 5, [(90, 1)])
    builder.add_entry(gps_ifd, 0x0012, 2, "WGS-84")
    builder.add_entry(gps_ifd, 0x001D, 2, config.datetime_original.split(" ")[0])

    exif_bytes = builder.build(ifd0)
    segment = b"\xff\xe1" + struct.pack(">H", len(exif_bytes) + 2) + exif_bytes
    jpeg_bytes = b"\xff\xd8" + segment + b"\xff\xd9"

    target = FIXTURE_DIR / config.filename
    target.write_bytes(jpeg_bytes)


if __name__ == "__main__":
    for fixture in FIXTURES:
        build_fixture(fixture)
    print(f"Updated {len(FIXTURES)} EXIF fixtures")
