<?php
/**
 * خواننده XLSX سبک و بدون وابستگی — خواندن استریمی فایل‌های اکسل (ایمپورت گروهی).
 * پشتیبانی: sharedStrings، رشته‌های inline، مقادیر عددی/بولی، سلول‌های خالی و مرجع ستون/ردیف.
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class TPP_XLSX_Reader {

        private $zip = null;         // ZipArchive (اگر افزونه php-zip فعال باشد)
        private $pure = null;        // TPP_Zip_Reader (جایگزین خالص PHP)
        private $shared = array();

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

        private function load_shared_strings() {
                $data = $this->zip_get( 'xl/sharedStrings.xml' );
                if ( false === $data || '' === $data ) {
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

        /** مسیر شیت اول */
        private function first_sheet_path() {
                $wb = $this->zip_get( 'xl/workbook.xml' );
                if ( false === $wb || '' === $wb ) {
                        return 'xl/worksheets/sheet1.xml';
                }
                $rid = '';
                if ( preg_match( '/<sheet\b[^>]*\br:id="(rId\d+)"/u', $wb, $m ) ) {
                        $rid = $m[1];
                } elseif ( preg_match( '/"r:id"\s*:\s*"(rId\d+)"/u', $wb, $m2 ) ) {
                        // بعضی مولدها attribute را جدا می‌نویسند
                        $rid = $m2[1];
                }
                if ( '' !== $rid ) {
                        $rels = $this->zip_get( 'xl/_rels/workbook.xml.rels' );
                        if ( false !== $rels && preg_match( '/<Relationship\b[^>]*\bId="' . preg_quote( $rid, '/' ) . '"[^>]*\bTarget="([^"]+)"/u', $rels, $rm ) ) {
                                $target = ltrim( $rm[1], '/' );
                                if ( 0 !== strpos( $target, 'xl/' ) ) {
                                        $target = 'xl/' . $target;
                                }
                                return $target;
                        }
                }
                return 'xl/worksheets/sheet1.xml';
        }

        /**
         * خواندن ردیف‌ها — خروجی: آرایه‌ای از ردیف‌ها (هر ردیف آرایه مقادیر با ایندکس ستون 0-based)
         * $max_rows: سقف تعداد ردیف
         */
        public function rows( $max_rows = 20000 ) {
                if ( ! $this->zip && ! $this->pure ) {
                        return false;
                }
                $path = $this->first_sheet_path();
                $data = $this->zip_get( $path );
                if ( false === $data || '' === $data ) {
                        // تلاش برای شیت ۱
                        $data = $this->zip_get( 'xl/worksheets/sheet1.xml' );
                        if ( false === $data ) {
                                return array();
                        }
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

        /** خواندن سلول‌های یک ردیف */
        private function read_row( XMLReader $reader ) {
                $row      = array();
                $max_col  = -1;
                $cell_col = 0;
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

        /** مقدار یک سلول بر اساس نوع */
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
                                if ( 's' === $type ) {
                                        $idx = (int) $raw;
                                        $value = isset( $this->shared[ $idx ] ) ? $this->shared[ $idx ] : '';
                                } elseif ( 'b' === $type ) {
                                        $value = $raw ? '1' : '';
                                } else {
                                        // عدد: حذف صفرهای اعشاری بی‌معنا (53.0 → 53)
                                        if ( is_numeric( $raw ) && false === strpos( $raw, 'E' ) && false === strpos( $raw, 'e' ) ) {
                                                if ( floor( (float) $raw ) == (float) $raw && strlen( (string) (int) $raw ) <= 15 ) {
                                                        $raw = (string) (int) $raw;
                                                } else {
                                                        $raw = rtrim( rtrim( $raw, '0' ), '.' );
                                                }
                                        }
                                        $value = $raw;
                                }
                                break;
                        }
                }
                return $value;
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
