<?php
/**
 * خواننده XLSX سبک و بدون وابستگی — خواندن استریمی فایل‌های اکسل (ایمپورت گروهی).
 * پشتیبانی: sharedStrings، رشته‌های inline، مقادیر عددی/بولی، سلول‌های خالی و مرجع ستون/ردیف.
 *
 * ۱.۳۱.۰ — رفع خطای «فایل اکسل معتبر نیست / خطا در خواندن فایل» روی هاست‌هایی که
 * افزونه XMLReader (php-xml) ندارند: تا پیش از این نسخه XMLReader وابستگی سخت بود و
 * نبود آن، ایمپورت را برای «همه فایل‌ها» از کار می‌انداخت. حالا یک تجزیه‌گر XML خالص
 * PHP (بدون هیچ افزونه) جایگزین خودکار می‌شود. همچنین:
 *  - شیت‌ها به‌ترتیب workbook فهرست می‌شوند (sheets/rows_from) تا ایمپورت بتواند اگر
 *    شیت اول خالی بود، از اولین شیت دارای داده بخواند.
 *  - سلول‌های بدون صفت r (مولدهای مینیمال) دیگر یک ستون جابه‌جا نمی‌شوند (شروع از ۱ → ۰).
 *  - کلاس/نام شیت‌ها برای پیام خطای دقیق‌تر در اختیار preview گذاشته می‌شود.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TPP_XLSX_Reader {

	private $zip = null;         // ZipArchive (اگر افزونه php-zip فعال باشد)
	private $pure = null;        // TPP_Zip_Reader (جایگزین خالص PHP)
	private $shared = array();
	private $use_pure_xml = false; // ۱.۳۱.۰ — XMLReader در دسترس نیست؟

	/**
	 * باز کردن فایل — false اگر ZIP معتبر نباشد.
	 * اگر افزونه php-zip (ZipArchive) روی میزبان فعال نباشد، خودکار از خواننده خالص PHP استفاده می‌شود.
	 */
	public function open( $file ) {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		$head = file_get_contents( $file, false, null, 0, 4 );
		if ( "PK\x03\x04" !== $head ) {
			return false; // فایل اکسل (zip) نیست
		}
		$opened = false;
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = @new ZipArchive();
			if ( true === @$zip->open( $file ) ) {
				$this->zip = $zip;
				$opened    = true;
			}
		}
		if ( ! $opened ) {
			// میزبان بدون php-zip یا فایل غیرقابل‌خواندن با ZipArchive → خواننده خالص PHP
			$pure = new TPP_Zip_Reader();
			if ( ! $pure->open( $file ) ) {
				return false;
			}
			$this->pure = $pure;
		}
		$this->use_pure_xml = ! class_exists( 'XMLReader' );
		$this->load_shared_strings();
		return true;
	}

	/** محتوای یک عضو آرشیو (از هر دو بک‌اند) */
	private function zip_get( $name ) {
		if ( null !== $this->zip ) {
			return $this->zip->getFromName( $name );
		}
		if ( null !== $this->pure ) {
			$data = $this->pure->get( $name );
			return false === $data ? false : $data;
		}
		return false;
	}

	/** ۱.۳۱.۰ — آیا عضوی با این نام در آرشیو هست؟ */
	private function zip_has( $name ) {
		if ( null !== $this->zip ) {
			return false !== $this->zip->locateName( $name );
		}
		if ( null !== $this->pure ) {
			return $this->pure->has( $name );
		}
		return false;
	}

	/* ==================== sharedStrings ==================== */

	private function load_shared_strings() {
		$data = $this->zip_get( 'xl/sharedStrings.xml' );
		if ( false === $data || '' === $data ) {
			return;
		}
		if ( $this->use_pure_xml ) {
			$this->load_shared_strings_pure( (string) $data );
			return;
		}
		$reader = new XMLReader();
		if ( ! @$reader->XML( $data, 'UTF-8' ) ) {
			return;
		}
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
				$this->shared[] = $this->read_si_text( $reader );
			}
		}
		$reader->close();
	}

	/** ۱.۳۱.۰ — sharedStrings بدون XMLReader */
	private function load_shared_strings_pure( $data ) {
		if ( ! preg_match_all( '/<si\b[^>]*>(.*?)<\/si\s*>/s', $data, $ms ) ) {
			return;
		}
		foreach ( $ms[1] as $si ) {
			$this->shared[] = self::pure_concat_t( $si );
		}
	}

	/** متن یک عنصر <si> (پشتیبانی از rich text چندتکه) */
	private function read_si_text( XMLReader $reader ) {
		$text  = '';
		$depth = $reader->depth;
		// حرکت داخل درخت si تا پایان آن
		while ( $reader->read() ) {
			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
				break;
			}
			if ( XMLReader::ELEMENT === $reader->nodeType && 't' === $reader->localName ) {
				$text .= (string) $reader->readString();
			}
		}
		return $text;
	}

	/* ==================== شیت‌ها (۱.۳۱.۰) ==================== */

	/**
	 * ۱.۳۱.۰ — فهرست شیت‌ها به‌ترتیب workbook: [ ['name'=>.., 'path'=>..], ... ]
	 * مسیر هر شیت از r:id + workbook.xml.rels حل می‌شود؛ بدون r:id از sheetId/ترتیب حدس زده
	 * و با وجود عضو در آرشیو تأیید می‌شود. در بدترین حالت sheet1.xml پیش‌فرض است.
	 */
	public function sheets() {
		$default = array( array( 'name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml' ) );
		$wb      = $this->zip_get( 'xl/workbook.xml' );
		if ( false === $wb || '' === $wb ) {
			return $default;
		}
		$rels = $this->zip_get( 'xl/_rels/workbook.xml.rels' );
		$rel_map = array();
		if ( false !== $rels && '' !== $rels ) {
			if ( preg_match_all( '/<Relationship\b[^>]*>/u', (string) $rels, $rms ) ) {
				foreach ( $rms[0] as $tag ) {
					$id  = self::pure_attr( $tag, 'Id' );
					$tgt = self::pure_attr( $tag, 'Target' );
					if ( null !== $id && null !== $tgt && '' !== $id && '' !== $tgt ) {
						$rel_map[ $id ] = $tgt;
					}
				}
			}
		}

		$sheets = array();
		$seen   = array();
		if ( preg_match_all( '/<sheet\b[^>]*\/?>/u', (string) $wb, $sms ) ) {
			foreach ( $sms[0] as $i => $tag ) {
				$name = self::pure_attr( $tag, 'name' );
				if ( null === $name || '' === $name ) {
					$name = 'Sheet' . ( $i + 1 );
				}
				$rid = self::pure_attr( $tag, 'r:id' );
				if ( '' === (string) $rid && preg_match( '/\br:id="(rId\d+)"/u', $tag, $rm ) ) {
					$rid = $rm[1]; // بعضی مولدها r:id را جدا می‌نویسند
				}
				$path = '';
				if ( '' !== (string) $rid && isset( $rel_map[ $rid ] ) ) {
					$path = ltrim( (string) $rel_map[ $rid ], '/' );
					if ( 0 !== strpos( $path, 'xl/' ) ) {
						$path = 'xl/' . $path;
					}
				} else {
					$sid = self::pure_attr( $tag, 'sheetId' );
					$n   = ( null !== $sid && ctype_digit( (string) $sid ) ) ? (int) $sid : ( $i + 1 );
					$guess = 'xl/worksheets/sheet' . $n . '.xml';
					if ( $this->zip_has( $guess ) ) {
						$path = $guess;
					}
				}
				if ( '' !== $path && $this->zip_has( $path ) && ! isset( $seen[ $path ] ) ) {
					$sheets[]       = array( 'name' => $name, 'path' => $path );
					$seen[ $path ]  = true;
				}
			}
		}
		return empty( $sheets ) ? $default : $sheets;
	}

	/** مسیر شیت اول (سازگاری با نسخه‌های قبل) */
	private function first_sheet_path() {
		$sheets = $this->sheets();
		return $sheets[0]['path'];
	}

	/* ==================== خواندن ردیف‌ها ==================== */

	/**
	 * خواندن ردیف‌های شیت اول — خروجی: آرایه‌ای از ردیف‌ها (هر ردیف آرایه مقادیر با ایندکس ستون 0-based)
	 * $max_rows: سقف تعداد ردیف
	 */
	public function rows( $max_rows = 20000 ) {
		return $this->rows_from( $this->first_sheet_path(), $max_rows );
	}

	/**
	 * ۱.۳۱.۰ — خواندن ردیف‌های یک شیت مشخص (مسیر داخل آرشیو).
	 */
	public function rows_from( $sheet_path, $max_rows = 20000 ) {
		if ( ! $this->zip && ! $this->pure ) {
			return false;
		}
		$data = $this->zip_get( (string) $sheet_path );
		if ( false === $data || '' === $data ) {
			// تلاش برای شیت ۱
			$data = $this->zip_get( 'xl/worksheets/sheet1.xml' );
			if ( false === $data ) {
				return array();
			}
		}

		if ( $this->use_pure_xml ) {
			return $this->rows_pure( (string) $data, $max_rows );
		}

		$rows   = array();
		$reader = new XMLReader();
		if ( ! @$reader->XML( $data, 'UTF-8' ) ) {
			return array();
		}
		$row_count = 0;
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'row' !== $reader->localName ) {
				continue;
			}
			$row_count++;
			if ( $row_count > $max_rows ) {
				break;
			}
			$rows[] = $this->read_row( $reader );
		}
		$reader->close();
		return $rows;
	}

	/** خواندن سلول‌های یک ردیف (مسیر XMLReader) */
	private function read_row( XMLReader $reader ) {
		$row      = array();
		$max_col  = -1;
		$cell_col = -1; // ۱.۳۱.۰ — اولین سلولِ بدون r روی ستون ۰ می‌نشیند (قبلاً ۱ بود)
		while ( $reader->read() ) {
			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'row' === $reader->localName ) {
				break;
			}
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'c' !== $reader->localName ) {
				continue;
			}
			// مرجع سلول مثل A1 → ایندکس عددی
			$ref = $reader->getAttribute( 'r' );
			if ( $ref && preg_match( '/^([A-Z]+)/', $ref, $rm ) ) {
				$cell_col = self::letters_to_index( $rm[1] );
			} else {
				$cell_col++; // بدون ref: ترتیبی
			}
			$type = (string) $reader->getAttribute( 't' );
			$val  = $this->read_cell_value( $reader, $type );
			if ( $cell_col > $max_col ) {
				$max_col = $cell_col;
			}
			$row[ $cell_col ] = $val;
		}
		// آرایه پیوسته از 0
		$out = array();
		for ( $i = 0; $i <= $max_col; $i++ ) {
			$out[ $i ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
		}
		return $out;
	}

	/** مقدار یک سلول بر اساس نوع (مسیر XMLReader) */
	private function read_cell_value( XMLReader $reader, $type ) {
		$value = '';
		if ( 'inlineStr' === $type ) {
			// <c t="inlineStr"><is><t>متن</t></is></c>
			$depth = $reader->depth;
			while ( $reader->read() ) {
				if ( $reader->depth <= $depth ) {
					break;
				}
				if ( XMLReader::ELEMENT === $reader->nodeType && 't' === $reader->localName ) {
					$value .= (string) $reader->readString();
				}
				if ( XMLReader::END_ELEMENT === $reader->nodeType && 'is' === $reader->localName ) {
					break;
				}
			}
			return $value;
		}
		// <c t="s"><v>index</v></c> یا <c><v>عدد</v></c> یا <c t="str"><v>نتیجه فرمول</v></c>
		$depth = $reader->depth;
		while ( $reader->read() ) {
			if ( $reader->depth <= $depth ) {
				break;
			}
			if ( XMLReader::END_ELEMENT === $reader->nodeType && 'c' === $reader->localName ) {
				break;
			}
			if ( XMLReader::ELEMENT === $reader->nodeType && 'v' === $reader->localName ) {
				$raw = (string) $reader->readString();
				$value = self::typed_value( $raw, $type, $this->shared );
				break;
			}
		}
		return $value;
	}

	/* ==================== ۱.۳۱.۰ — تجزیه‌گر XML خالص (بدون XMLReader) ==================== */

	/** خواندن ردیف‌ها از XML شیت بدون XMLReader */
	private function rows_pure( $xml, $max_rows ) {
		$rows = array();
		if ( ! preg_match_all( '/<row\b[^>]*\/>|<row\b[^>]*>.*?<\/row\s*>/s', $xml, $rms ) ) {
			return $rows;
		}
		$count = 0;
		foreach ( $rms[0] as $row_xml ) {
			$count++;
			if ( $count > $max_rows ) {
				break;
			}
			$rows[] = $this->read_row_pure( $row_xml );
		}
		return $rows;
	}

	/** خواندن سلول‌های یک ردیف (مسیر خالص) */
	private function read_row_pure( $row_xml ) {
		$row      = array();
		$max_col  = -1;
		$cell_col = -1; // اولین سلولِ بدون r روی ستون ۰ می‌نشیند
		if ( ! preg_match_all( '/<c\b[^>]*\/>|<c\b[^>]*>.*?<\/c\s*>/s', $row_xml, $cms ) ) {
			return array();
		}
		foreach ( $cms[0] as $cell ) {
			$ref = self::pure_attr( $cell, 'r' );
			if ( null !== $ref && preg_match( '/^([A-Z]+)/', (string) $ref, $rm ) ) {
				$cell_col = self::letters_to_index( $rm[1] );
			} else {
				$cell_col++;
			}
			$type = (string) ( self::pure_attr( $cell, 't' ) );
			$val  = $this->read_cell_value_pure( $cell, $type );
			if ( $cell_col > $max_col ) {
				$max_col = $cell_col;
			}
			$row[ $cell_col ] = $val;
		}
		$out = array();
		for ( $i = 0; $i <= $max_col; $i++ ) {
			$out[ $i ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
		}
		return $out;
	}

	/** مقدار یک سلول (مسیر خالص) — هم‌سنتن مسیر XMLReader */
	private function read_cell_value_pure( $cell, $type ) {
		if ( 'inlineStr' === $type ) {
			return self::pure_concat_t( $cell );
		}
		$raw = '';
		if ( preg_match( '/<v\b[^>]*>(.*?)<\/v\s*>/s', $cell, $vm ) ) {
			$raw = self::xml_decode( $vm[1] );
		} elseif ( preg_match( '/<v\b[^>]*\/>/s', $cell, $vm2 ) ) {
			$raw = '';
		}
		return self::typed_value( trim( (string) $raw ), $type, $this->shared );
	}

	/** مقدار نوع‌بندی‌شده سلول — مشترک بین دو مسیر */
	private static function typed_value( $raw, $type, array $shared ) {
		$raw = (string) $raw;
		if ( 's' === $type ) { // shared string
			$idx = (int) $raw;
			return isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
		}
		if ( 'b' === $type ) { // boolean
			$v = strtolower( trim( $raw ) );
			return ( '1' === $v || 'true' === $v ) ? '1' : '';
		}
		// عدد یا نتیجه فرمول: حذف صفرهای اعشاری بی‌معنا (53.0 → 53)
		if ( is_numeric( $raw ) && false === strpos( $raw, 'E' ) && false === strpos( $raw, 'e' ) ) {
			if ( floor( (float) $raw ) == (float) $raw && strlen( (string) (int) $raw ) <= 15 ) {
				return (string) (int) $raw;
			}
			return rtrim( rtrim( $raw, '0' ), '.' );
		}
		return $raw;
	}

	/** ۱.۳۱.۰ — مقدار صفت از تگ (بدون DOM) — null اگر نباشد */
	private static function pure_attr( $tag, $name ) {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '="([^"]*)"/u', (string) $tag, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** ۱.۳۱.۰ — چسباندن متن همه <t>های یک قطعه (rich text چندتکه را هم پوشش می‌دهد) */
	private static function pure_concat_t( $fragment ) {
		$text = '';
		if ( preg_match_all( '/<t\b[^>]*>(.*?)<\/t\s*>|<t\b[^>]*\/>/s', (string) $fragment, $tms ) ) {
			foreach ( $tms[0] as $i => $whole ) {
				if ( '' === (string) $tms[1][ $i ] && 0 === strpos( $whole, '<t' ) && '/>' === substr( rtrim( $whole ), -2 ) ) {
					continue; // <t/> خودبسته = خالی
				}
				$text .= self::xml_decode( $tms[1][ $i ] );
			}
		}
		return $text;
	}

	/** ۱.۳۱.۰ — حل CDATA + موجودیت‌های XML (نام‌دار + عددی) */
	private static function xml_decode( $text ) {
		$text = (string) $text;
		// CDATA: فقط محتوای داخلش
		if ( preg_match( '/^\s*<!\[CDATA\[(.*)\]\]>\s*$/s', $text, $cm ) ) {
			return $cm[1];
		}
		if ( false === strpos( $text, '&' ) ) {
			return $text;
		}
		$text = preg_replace_callback( '/&#x([0-9A-Fa-f]+);|&#([0-9]+);/', array( __CLASS__, 'xml_decode_numeric' ), $text );
		$map  = array( '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'", '&amp;' => '&' );
		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}

	private static function xml_decode_numeric( $m ) {
		$cp = '' !== $m[1] ? hexdec( $m[1] ) : (int) $m[2];
		if ( $cp <= 0 ) {
			return '';
		}
		return mb_chr( $cp, 'UTF-8' );
	}

	/** تبدیل حروف ستون به ایندکس (A→0) */
	public static function letters_to_index( $letters ) {
		$index = 0;
		$len   = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $index - 1;
	}

	public function close() {
		if ( $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}
		$this->pure = null;
	}

	public function __destruct() {
		$this->close();
	}
}
