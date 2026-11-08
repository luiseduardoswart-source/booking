<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Property;
use App\Models\User;
use App\Support\DocumentNotReadable;
use App\Support\DocumentText;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Uploading an existing rental contract: the text goes into the Rental terms box.
 */
class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));

        parent::tearDown();
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }

    /** A minimal but real .docx file with these paragraphs (raw XML). */
    private function word(string $body, ?string $documentXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $this->files[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', $documentXml ?? '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return $path;
    }

    private function pdf(string $html): string
    {
        return $this->file(Pdf::loadHTML($html)->output());
    }

    private function error(string $path): string
    {
        try {
            DocumentText::fromPath($path);
        } catch (DocumentNotReadable $e) {
            return $e->getMessage();
        }

        $this->fail('Expected the document to be rejected.');
    }

    public function test_word_paragraphs_tabs_and_line_breaks_stay_are(): void
    {
        $path = $this->word(
            '<w:p><w:r><w:t>Huurcontract</w:t></w:r></w:p>'
            .'<w:p/>'
            .'<w:p><w:r><w:t xml:space="preserve">Artikel 1. </w:t></w:r><w:r><w:t>De huurder</w:t></w:r><w:r><w:br/><w:t>betaalt vooraf.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Borg</w:t><w:tab/><w:t>€ 300</w:t></w:r></w:p>'
        );

        $this->assertSame("Huurcontract\n\nArtikel 1. De huurder\nbetaalt vooraf.\nBorg\t€ 300", DocumentText::fromPath($path));
    }

    public function test_deleted_text_off_changes_track_counts_not_along(): void
    {
        $path = $this->word('<w:p><w:r><w:t>Borg € 300</w:t></w:r><w:del><w:r><w:delText> (vervalt)</w:delText></w:r></w:del></w:p>');

        $this->assertSame('Borg € 300', DocumentText::fromPath($path));
    }

    public function test_pdf_text_is_read(): void
    {
        $text = DocumentText::fromPath($this->pdf('<h1>Huurcontract</h1><p>Artikel 1. De huurder betaalt vooraf.</p>'));

        $this->assertStringContainsString('Huurcontract', $text);
        $this->assertStringContainsString('De huurder betaalt vooraf.', $text);
    }

    public function test_text_files_off_notepad_are_utf8(): void
    {
        $this->assertSame('Borg € 300', DocumentText::fromPath($this->file("Borg \x80 300")));
        $this->assertSame('Borg € 300', DocumentText::fromPath($this->file("\xFF\xFE".mb_convert_encoding('Borg € 300', 'UTF-16LE', 'UTF-8'))));
        $this->assertSame("Regel 1\n\nRegel 2", DocumentText::fromPath($this->file("\xEF\xBB\xBFRegel 1   \r\n\r\n\r\n\r\nRegel 2\r\n")));
    }

    public function test_a_pdf_without_text_gives_a_understandable_notification(): void
    {
        $this->assertStringContainsString('gescande PDF', $this->error($this->pdf('<p>&nbsp;</p>')));
    }

    public function test_other_file_types_are_rejected(): void
    {
        $png = $this->file(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        $this->assertStringContainsString('niet ondersteund', $this->error($png));
    }

    public function test_a_zip_without_word_document_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $this->files[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('iets.txt', 'Hallo');
        $zip->close();

        $this->assertStringContainsString('geen geldig Word-bestand', $this->error($path));
    }

    public function test_a_word_file_that_unpacked_to_large_is_is_rejected(): void
    {
        // Compresses to a few kilobytes, but unpacks to above the limit.
        $path = $this->word('', str_repeat(' ', DocumentText::MAX_WORD_XML + 1));

        $this->assertStringContainsString('te veel tekst', $this->error($path));
    }

    public function test_a_word_file_with_doctype_is_not_processed(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&b;</w:t></w:r></w:p></w:body></w:document>';

        $this->assertStringContainsString('geen geldig Word-bestand', $this->error($this->word('', $xml)));
    }

    public function test_a_to_large_file_is_rejected(): void
    {
        $path = $this->file(str_repeat('a', DocumentText::MAX_BYTES + 1));

        $this->assertStringContainsString('groter dan 10 MB', $this->error($path));
    }

    private function upload(string $path, string $name, string $language): Testable
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        return Livewire::actingAs($admin)
            ->test(EditDefaults::class)
            ->callAction(
                TestAction::make('upload_rental_terms')->schemaComponent('rental_terms_section', schema: 'form'),
                ['file' => UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path)), 'language' => $language],
            );
    }

    public function test_the_button_set_the_text_in_the_main_field_without_on_to_store(): void
    {
        $path = $this->word('<w:p><w:r><w:t>Huurcontract</w:t></w:r></w:p>');

        $this->upload($path, 'contract.docx', 'en')
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['rental_terms' => 'Huurcontract'], 'form')
            ->assertNotified(__('Text imported. Check it and save.'));

        $this->assertNull(Property::master()->fresh()->rental_terms);
    }

    public function test_the_button_set_a_spanish_contract_in_the_spanish_box(): void
    {
        Property::master()->update(['rental_terms' => 'Bestaande tekst']);

        $this->upload($this->pdf('<p>Contrato de alquiler</p>'), 'contract.pdf', 'es')
            ->assertSchemaStateSet([
                'rental_terms' => 'Bestaande tekst',
                'rental_terms_translations.es' => 'Contrato de alquiler',
            ], 'form');
    }

    public function test_a_unreadable_file_gives_a_notification_and_lets_the_box_are(): void
    {
        Property::master()->update(['rental_terms' => 'Bestaande tekst']);

        $this->upload($this->pdf('<p>&nbsp;</p>'), 'scan.pdf', 'nl')
            ->assertSchemaStateSet(['rental_terms' => 'Bestaande tekst'], 'form')
            ->assertNotified(__('Import failed'));
    }
}
