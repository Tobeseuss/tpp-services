<?php
/**
 * سند PDF گزارش — زیرکلاس TCPDF با سرصفحه گزارش + تکرار سرستون جدول در همه صفحات + پاصفحه.
 * این فایل فقط بعد از بارگذاری TCPDF لازم است (خودکار در TPP_PDF_Report::build لود می‌شود).
 */
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}
if ( class_exists( 'TCPDF' ) && ! class_exists( 'TPP_Tcpdf_Doc' ) ) {

/**
 * سند TCPDF با سرصفحه گزارش + تکرار سرستون جدول در همه صفحات + پاصفحه شماره صفحه.
 */
class TPP_Tcpdf_Doc extends TCPDF {

        /** @var string عنوان گزارش */
        public $report_title = '';

        /** @var array خطوط اطلاعات زیر عنوان */
        public $report_meta = array();

        /** @var array سرستون‌های جدول */
        public $table_headers = array();

        /** @var array عرض ستون‌ها */
        public $table_widths = array();

        /** @var bool جدول فعال است (سرستون تکرار شود) */
        public $table_active = false;

        public function Header() {
                // نوار عنوان
                $this->SetFillColor( 31, 78, 121 );
                $this->SetTextColor( 255 );
                $this->SetFont( 'dejavusans', 'B', 12.5 );
                $this->SetY( 6, false );
                $this->Cell( 0, 7.5, $this->report_title, 0, 2, 'C', true );

                // خطوط اطلاعات (صفحه اول) / یادآوری (صفحه‌های بعد)
                $this->SetFont( 'dejavusans', '', 8 );
                $this->SetTextColor( 92, 102, 114 );
                if ( 1 === $this->PageNo() ) {
                        foreach ( $this->report_meta as $line ) {
                                $this->Cell( 0, 4.3, $line, 0, 1, 'C', false );
                        }
                } else {
                        $this->Cell( 0, 4.3, 'ادامه گزارش', 0, 1, 'C', false );
                }

                if ( $this->table_active && ! empty( $this->table_headers ) ) {
                        $this->SetY( max( 22, $this->GetY() + 1.6 ), false );
                        $this->draw_table_header();
                }
        }

        public function Footer() {
                $this->SetY( -10, true );
                $this->SetFont( 'dejavusans', '', 7.5 );
                $this->SetTextColor( 122, 132, 144 );
                $this->Cell( 0, 5, 'صفحه ' . $this->getAliasNumPage() . ' از ' . $this->getAliasNbPages() . ' — تولیدشده توسط افزونه TPP Services', 0, 0, 'C' );
        }

        /** رسم ردیف سرستون جدول */
        public function draw_table_header() {
                $this->SetFillColor( 31, 78, 121 );
                $this->SetTextColor( 255 );
                $this->SetDrawColor( 201, 209, 218 );
                $this->SetLineWidth( 0.2 );
                $this->SetFont( 'dejavusans', 'B', 8.5 );
                $x = $this->getPageWidth() - 10;
                $y = $this->GetY();
                foreach ( $this->table_headers as $i => $h ) {
                        $w = $this->table_widths[ $i ];
                        $x -= $w;
                        $this->SetXY( $x, $y );
                        $this->Cell( $w, 7, $h, 1, 0, 'C', true );
                }
                $this->SetY( $y + 7, false );
        }
}
}
