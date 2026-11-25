<?php
declare(strict_types=1);
namespace Caleidos\Modules\Documents;
use Caleidos\Core\Repository;
use DomainException;
final class DocumentService
{
    public function __construct(private string $directory) {
        if (!is_dir($directory)) mkdir($directory, 0775, true);
    }
    public function upload(array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('Adjunta un PDF válido de hasta 10 MB.');
        $name = basename(str_replace('\\', '/', (string) $file['name']));
        $size = filesize($file['tmp_name']);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf' || $size === false || $size <= 0 || $size > 10 * 1024 * 1024 || mb_strlen($name) > 200) throw new DomainException('El archivo debe ser PDF, de hasta 10 MB y nombre de hasta 200 caracteres.');
        $handle = fopen($file['tmp_name'], 'rb');
        $header = fread($handle, 1024);
        fclose($handle);
        if (!str_contains($header, '%PDF-') || !is_uploaded_file($file['tmp_name'])) throw new DomainException('El archivo no contiene un PDF válido.');
        $storageName = Repository::id() . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $this->directory . '/' . $storageName)) throw new DomainException('No se pudo guardar el PDF.');
        return ['original_name' => $name, 'storage_name' => $storageName, 'file_size' => $size];
    }
    public function remove(?string $name): void {
        if ($name && preg_match('/^[a-f0-9]{32}\.pdf$/', $name) && is_file($this->directory . '/' . $name)) unlink($this->directory . '/' . $name);
    }
    public function contents(array $version, array $contract): string {
        if ($version['storage_name']) {
            if (!preg_match('/^[a-f0-9]{32}\.pdf$/', $version['storage_name'])) throw new DomainException('Documento no disponible.');
            $path = $this->directory . '/' . $version['storage_name'];
            if (!is_file($path)) throw new DomainException('No se encontró el documento adjunto.');
            return file_get_contents($path);
        }
        return $this->demoPdf($contract['code'] . ' - ' . $contract['title'] . "\n\n" . $version['clause_text']);
    }
    public function demoPdf(string $text): string {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $lines = explode("\n", wordwrap("CALEIDOS - DOCUMENTO DE DEMOSTRACION\n\n" . $text . "\n\nDocumento ficticio. Sin firma real.", 88));
        $stream = "BT /F1 10 Tf 40 790 Td 14 TL\n";
        foreach (array_slice($lines, 0, 51) as $line) $stream .= '(' . str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $line) . ") Tj T*\n";
        $stream .= 'ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream"];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $i => $object) { $offsets[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
