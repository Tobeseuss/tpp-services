<?php
/**
 * نویسنده XLSX سبک و بدون وابستگی — تولید فایل اکسل معتبر با:
 *  - بسته‌بندی ZIP با TPP_Zip (خالص PHP؛ بدون نیاز به افزونه php-zip هاست)
 *  - شیت راست‌چین (فارسی)، ردیف سرستون رنگی و فریز‌شده، عرض ستون خودکار
 *  - متن به‌صورت inlineStr، پاکسازی کاراکترهای کنترلی غیرمجاز XML
 *  - خروجی دانلود با پاک‌سازی بافر (رفع خرابی فایل روی هاست‌های با zlib/gzip output)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TPP_XLSX_Writer {

	/** تبدیل شماره ستون به حرف (0→A) */
	public static function col_letter( $index ) {
		$letter = '';
		$index  = (int) $index;
		while ( $index >= 0 ) {
			$letter = chr( 65 + ( $index % 26 ) ) . $letter;
			$index  = intdiv( $index, 26 ) - 1;
		}
		return $letter;
	}

	/** فرار XML + حذف کاراکترهای کنترلی نامعتبر (0x00-0x08, 0x0B-0x1F جز \t \n) */
	private static function esc( $text ) {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text );
		if ( null === $text ) { // UTF-8 خراب → پاکسازی بایت‌های نامعتبر
			$text = mb_convert_encoding( (string) $text, 'UTF-8', 'UTF-8' );
		}
		return htmlspecialchars( $text, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}

	/**
	 * ساخت فایل XLSX
	 * $headers: آرایه رشته‌ای سرستون‌ها
	 * $rows: آرایه‌ای از آرایه‌های مقادیر
	 * $meta: ['sheet_name'=>..., 'title'=>...] اختیاری
	 * خروجی: رشته باینری XLSX یا WP_Error
	 */
	public static function build( array $headers, array $rows, array $meta = array() ) {
		$sheet_name = isset( $meta['sheet_name'] ) ? $meta['sheet_name'] : 'گزارش';
		$sheet_name = mb_substr( preg_replace( '/[\[\]\*\:\/\?\\\\]/u', '', $sheet_name ), 0, 30 );
		if ( '' === $sheet_name || null === $sheet_name ) {
			$sheet_name = 'Sheet1';
		}

		// عرض ستون‌ها بر اساس طولانی‌ترین مقدار
		$widths = array();
		foreach ( $headers as $i => $h ) {
			$widths[ $i ] = max( 8, min( 45, mb_strlen( (string) $h ) + 4 ) );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $i => $val ) {
				$len = mb_strlen( (string) $val );
				if ( $len > $widths[ $i ] ) {
					$widths[ $i ] = min( 60, $len + 3 );
				}
			}
		}

		$max_col = max( 1, count( $headers ) );

		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$xml .= '<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>';
		$xml .= '<dimension ref="A1:' . self::col_letter( $max_col - 1 ) . ( count( $rows ) + 1 ) . '"/>';
		$xml .= '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		$xml .= '<sheetFormatPr defaultRowHeight="18"/>';
		$xml .= '<cols>';
		foreach ( $widths as $i => $w ) {
			$xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		$xml .= '</cols><sheetData>';

		// سرستون‌ها
		$xml .= '<row r="1" ht="24" customHeight="1" s="1" customFormat="1">';
		foreach ( $headers as $i => $h ) {
			$ref = self::col_letter( $i ) . '1';
			$xml .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">' . self::esc( $h ) . '</t></is></c>';
		}
		$xml .= '</row>';

		// ردیف‌های داده (مقدار «0» هم نوشته می‌شود — مقدار خالی فقط برای رشته خالی)
		$r = 2;
		foreach ( $rows as $row ) {
			$xml .= '<row r="' . $r . '">';
			$i = 0;
			foreach ( (array) $row as $val ) {
				$val = (string) $val;
				if ( '' !== $val ) {
					$ref = self::col_letter( $i ) . $r;
					$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc( $val ) . '</t></is></c>';
				}
				$i++;
			}
			$xml .= '</row>';
			$r++;
		}
		$xml .= '</sheetData></worksheet>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
			. '</Types>';

		$rels_root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			. '</Relationships>';

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<workbookPr/>'
			. '<sheets><sheet name="' . self::esc( $sheet_name ) . '" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';

		$wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="0"/>'
			. '<fonts count="2">'
			. '<font><sz val="11"/><name val="Tahoma"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font>'
			. '</fonts>'
			. '<fills count="3">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF2C5F8A"/><bgColor indexed="64"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="2">'
			. '<border><left/><right/><top/><bottom/><diagonal/></border>'
			. '<border><left style="thin"><color rgb="FFBBBBBB"/></left><right style="thin"><color rgb="FFBBBBBB"/></right><top style="thin"><color rgb="FFBBBBBB"/></top><bottom style="thin"><color rgb="FFBBBBBB"/></bottom><diagonal/></border>'
			. '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="3">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" horizontal="right" readingOrder="2" wrapText="1"/></xf>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="center" readingOrder="2"/></xf>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" horizontal="right" readingOrder="2" wrapText="1"/></xf>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';

		$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:creator>TPP Services</dc:creator><cp:lastModifiedBy>TPP Services</cp:lastModifiedBy>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created>'
			. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:modified>'
			. '</cp:coreProperties>';

		$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>TPP Services</Application></Properties>';

		$zip = new TPP_Zip();
		$zip->add_file( '[Content_Types].xml', $content_types, true );
		$zip->add_file( '_rels/.rels', $rels_root, true );
		$zip->add_file( 'docProps/core.xml', $core, true );
		$zip->add_file( 'docProps/app.xml', $app, true );
		$zip->add_file( 'xl/workbook.xml', $workbook, true );
		$zip->add_file( 'xl/_rels/workbook.xml.rels', $wb_rels, true );
		$zip->add_file( 'xl/styles.xml', $styles, true );
		$zip->add_file( 'xl/worksheets/sheet1.xml', $xml, true );

		$content = $zip->build();
		if ( strlen( $content ) < 100 ) {
			return new WP_Error( 'tpp_zip_failed', 'ایجاد فایل اکسل ناموفق بود.' );
		}
		return $content;
	}

	/** ارسال فایل به مرورگر برای دانلود (با پاک‌سازی بافرهای خروجی) */
	public static function download( $filename, $content ) {
		TPP_Zip::download(
			$filename,
			$content,
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}
}
