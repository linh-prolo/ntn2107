import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:ntn_employee/utils/formatters.dart';
import 'package:ntn_employee/utils/image_data.dart';
import 'package:ntn_employee/utils/json.dart';

void main() {
  group('Fmt', () {
    test('formats currency in Vietnamese style', () {
      expect(Fmt.currency(8240000), '8.240.000 ₫');
      expect(Fmt.currency(0), '0 ₫');
      expect(Fmt.currency(-50000), '-50.000 ₫');
    });

    test('formats numbers with comma decimals', () {
      expect(Fmt.number(2), '2');
      expect(Fmt.number(2.5), '2,5');
      expect(Fmt.number(1.25, decimals: 2), '1,25');
    });

    test('formats dates', () {
      final d = DateTime(2026, 10, 2, 8, 5);
      expect(Fmt.date(d), '02/10/2026');
      expect(Fmt.time(d), '08:05');
      expect(Fmt.apiDate(d), '2026-10-02');
      expect(Fmt.time(null), '--:--');
      expect(Fmt.weekdayDate(d), 'Thứ Sáu, 02/10/2026');
      expect(Fmt.relativeDay(d, now: DateTime(2026, 10, 2, 23)), 'Hôm nay');
      expect(Fmt.relativeDay(d, now: DateTime(2026, 10, 3)), 'Hôm qua');
      expect(Fmt.relativeDay(d, now: DateTime(2026, 10, 5)), '02/10/2026');
    });
  });

  group('json helpers', () {
    test('coerce loosely typed values', () {
      expect(asInt('12'), 12);
      expect(asInt(null, 3), 3);
      expect(asDouble('2.5'), 2.5);
      expect(asBool('1'), isTrue);
      expect(asBool(0), isFalse);
      expect(asDateTime('2026-10-02 08:05:00'), DateTime(2026, 10, 2, 8, 5));
      expect(asDateTime('0000-00-00'), isNull);
      expect(
        asMapList([
          {'a': 1},
          'x',
        ]),
        [
          {'a': 1},
        ],
      );
    });
  });

  group('image data', () {
    test('detects JPEG/PNG/WEBP and builds data URLs', () {
      final jpeg = Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0, 1, 2]);
      final png = Uint8List.fromList([
        0x89,
        0x50,
        0x4E,
        0x47,
        0x0D,
        0x0A,
        0x1A,
        0x0A,
      ]);
      final webp = Uint8List.fromList('RIFF\x00\x00\x00\x00WEBPVP8 '.codeUnits);
      expect(detectImageMime(jpeg), 'image/jpeg');
      expect(detectImageMime(png), 'image/png');
      expect(detectImageMime(webp), 'image/webp');
      expect(imageDataUrl(jpeg), startsWith('data:image/jpeg;base64,'));
    });

    test('rejects unknown formats', () {
      expect(detectImageMime(Uint8List.fromList([1, 2, 3, 4])), isNull);
      expect(imageDataUrl(Uint8List.fromList('GIF89a'.codeUnits)), isNull);
    });
  });
}
