<?php

namespace AK_Set\Tests\Support;

use AK_Set\Tests\TestCase;

class I18n_Files_Test extends TestCase
{
    private string $languages_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->languages_dir = dirname(__DIR__, 2) . '/languages';
    }

    public function test_pot_template_exists_and_is_valid(): void
    {
        $pot_file = $this->languages_dir . '/ak-product-set.pot';
        $this->assertFileExists($pot_file);
        $content = file_get_contents($pot_file);
        $this->assertStringContainsString('Project-Id-Version: AK Product Set', $content);
        $this->assertStringContainsString('X-Domain: ak-product-set', $content);
        $this->assertStringContainsString('msgid "Wybrane Weekendy [Produkty]"', $content);
        $this->assertStringContainsString('msgid "Rejestr i eksport uczestników"', $content);
    }

    public function test_polish_translations_exist_and_are_valid(): void
    {
        $po_file = $this->languages_dir . '/ak-product-set-pl_PL.po';
        $mo_file = $this->languages_dir . '/ak-product-set-pl_PL.mo';

        $this->assertFileExists($po_file);
        $this->assertFileExists($mo_file);

        $po_content = file_get_contents($po_file);
        $this->assertStringContainsString('"Language: pl_PL\n"', $po_content);
        $this->assertStringContainsString('msgstr "Specjalistyczne rozszerzenie WooCommerce', $po_content);
        $this->assertStringContainsString('msgstr "Wtyczka AK Product Set wymaga zainstalowania i aktywowania WooCommerce."', $po_content);

        // Check gettext binary magic number (0x950412de or 0xde120495)
        $mo_content = file_get_contents($mo_file);
        $magic = unpack('V', substr($mo_content, 0, 4))[1];
        $this->assertTrue($magic === 0x950412de || $magic === 0xde120495);
    }

    public function test_english_translations_exist_and_are_valid(): void
    {
        $po_file = $this->languages_dir . '/ak-product-set-en_US.po';
        $mo_file = $this->languages_dir . '/ak-product-set-en_US.mo';

        $this->assertFileExists($po_file);
        $this->assertFileExists($mo_file);

        $po_content = file_get_contents($po_file);
        $this->assertStringContainsString('"Language: en_US\n"', $po_content);
        $this->assertStringContainsString('msgstr "Selected Weekends [Products]"', $po_content);
        $this->assertStringContainsString('msgstr "Participant Roster & Export"', $po_content);
        $this->assertStringContainsString('msgstr "Download CSV [all dates]"', $po_content);

        // Check gettext binary magic number
        $mo_content = file_get_contents($mo_file);
        $magic = unpack('V', substr($mo_content, 0, 4))[1];
        $this->assertTrue($magic === 0x950412de || $magic === 0xde120495);
    }
}
