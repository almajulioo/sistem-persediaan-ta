<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Menempelkan QR mengambang ke DOCX yang sudah jadi.
 *
 * Template BAST dan Nota Dinas permohonan tidak punya tempat gambar, jadi QR
 * disisipkan sesudah TemplateProcessor mengisi teksnya. Gambarnya mengambang
 * di paragraf jangkarnya, sehingga tata letak template tidak bergeser.
 */
class DocxQrStamper
{
    public const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * $rencana menerima XPath dokumen (prefiks `w` sudah terdaftar) dan
     * mengembalikan tempelannya: nama berkas gambar, paragraf jangkar, isi PNG,
     * sisi dalam EMU (360.000 EMU = 1 cm), dan posisi mendatar dari
     * tengahKolom() atau dariKiri().
     *
     * @param  callable(DOMXPath): list<array{nama: string, paragraf: DOMElement, png: string, sisi: int, mendatar: string}>  $rencana
     */
    public function stamp(string $docxPath, callable $rencana): void
    {
        $zip = new ZipArchive;

        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException('DOCX tidak dapat dibuka untuk ditempeli QR.');
        }

        try {
            $document = new DOMDocument;
            $document->loadXML((string) $zip->getFromName('word/document.xml'));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('w', self::W);
            $tempelan = $rencana($xpath);

            if ($tempelan === []) {
                return;
            }

            $relasi = (string) $zip->getFromName('word/_rels/document.xml.rels');
            $tipe = (string) $zip->getFromName('[Content_Types].xml');

            foreach (array_values($tempelan) as $i => $qr) {
                $nomor = $i + 1;
                $zip->addFromString("word/media/qr-{$qr['nama']}.png", $qr['png']);
                $relasi = str_replace('</Relationships>', sprintf(
                    '<Relationship Id="rIdQr%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/qr-%s.png"/></Relationships>',
                    $nomor,
                    $qr['nama'],
                ), $relasi);
                $this->tempel($document, $qr['paragraf'], $nomor, $qr['sisi'], $qr['mendatar']);
            }

            if (! str_contains($tipe, 'Extension="png"')) {
                $tipe = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $tipe);
            }

            $zip->addFromString('word/document.xml', (string) $document->saveXML());
            $zip->addFromString('word/_rels/document.xml.rels', $relasi);
            $zip->addFromString('[Content_Types].xml', $tipe);
        } finally {
            $zip->close();
        }
    }

    /**
     * Rata tengah terhadap kolomnya; di dalam tabel, kolom berarti sel.
     */
    public static function tengahKolom(): string
    {
        return '<wp:positionH relativeFrom="column"><wp:align>center</wp:align></wp:positionH>';
    }

    /**
     * Berjarak $emu dari tepi kiri $relatifKe: `column` (sel, bila di dalam
     * tabel) atau `margin`.
     */
    public static function dariKiri(int $emu, string $relatifKe = 'column'): string
    {
        return sprintf('<wp:positionH relativeFrom="%s"><wp:posOffset>%d</wp:posOffset></wp:positionH>', $relatifKe, $emu);
    }

    private function tempel(DOMDocument $document, DOMElement $paragraf, int $nomor, int $sisi, string $mendatar): void
    {
        $fragmen = $document->createDocumentFragment();
        $fragmen->appendXML(sprintf(
            '<w:r xmlns:w="%1$s" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:drawing>'
            .'<wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251659264" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">'
            .'<wp:simplePos x="0" y="0"/>'
            .'%5$s<wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV>'
            .'<wp:extent cx="%2$d" cy="%2$d"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>'
            .'<wp:docPr id="%3$d" name="QR Verifikasi %3$d" descr="QR verifikasi persetujuan"/><wp:cNvGraphicFramePr/>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
            .'<pic:nvPicPr><pic:cNvPr id="%3$d" name="qr-%3$d.png"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rIdQr%4$d"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%2$d" cy="%2$d"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            .'</pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>',
            self::W,
            $sisi,
            9000 + $nomor,
            $nomor,
            $mendatar,
        ));

        $paragraf->appendChild($fragmen);
    }
}
