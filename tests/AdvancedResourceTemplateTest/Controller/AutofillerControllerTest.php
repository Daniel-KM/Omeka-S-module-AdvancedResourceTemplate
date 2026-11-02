<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Controller;

use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests of the routes of the autofillers set in the resource templates.
 *
 * @group mapper
 */
class AutofillerControllerTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * @var int
     */
    protected $templateId;

    public function setUp(): void
    {
        parent::setUp();
        if (!$this->getApplicationServiceLocator()->has('Mapper\Mapper')) {
            $this->markTestSkipped('Mapper module not available');
        }
        $this->loginAdmin();
        $this->templateId = $this->createTemplate('Autofiller template', [
            'autofillers' => [
                'idref:person',
                'geonames #places = mapping:999999',
                'generic:json #raw = {"params": {"url": "http://127.0.0.1/{query}"}, "maps": [{"from": {"path": "a"}, "to": {"field": "dcterms:title"}}]}',
                'generic:json #ini = [params] url = http://127.0.0.1/{query}',
            ],
        ])->id();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testSettingsReturnsLabel(): void
    {
        $this->dispatch('/admin/autofiller/settings', 'GET', [
            'service' => 'idref:person',
            'template' => $this->templateId,
        ], true);
        $this->assertResponseStatusCode(200);
        $json = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('IdRef: Person names', $json['data']['autofiller']['label']);
    }

    public function testSettingsWithVariantAndMapping(): void
    {
        $this->dispatch('/admin/autofiller/settings', 'GET', [
            'service' => 'geonames #places',
            'template' => $this->templateId,
        ], true);
        $this->assertResponseStatusCode(200);
        $json = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame('Geonames', $json['data']['autofiller']['label']);
    }

    public function testServiceNotInTemplate(): void
    {
        $this->dispatch('/admin/autofiller/settings', 'GET', [
            'service' => 'idref:corporation',
            'template' => $this->templateId,
        ], true);
        $this->assertResponseStatusCode(501);
    }

    /**
     * A mapping content in a template is refused, since a template may be
     * edited by non-admin users and the server would query any url.
     */
    public function testMappingContentIsRefused(): void
    {
        foreach (['generic:json #raw', 'generic:json #ini'] as $service) {
            $this->dispatch('/admin/autofiller', 'GET', [
                'service' => $service,
                'template' => $this->templateId,
                'q' => 'x',
            ], true);
            $this->assertResponseStatusCode(400);
            $json = json_decode($this->getResponse()->getBody(), true);
            $this->assertSame('error', $json['status'], $service);
        }
    }

    /**
     * A remote service that cannot be reached is reported as unavailable.
     */
    public function testUnreachableServiceIsUnavailable(): void
    {
        $userPath = $this->getApplicationServiceLocator()->get('Mapper\MappingPaths')->userPath();
        @mkdir($userPath, 0775, true);
        $filename = 'art_test_' . bin2hex(random_bytes(4)) . '.ini';
        file_put_contents($userPath . '/' . $filename, "[params]\nurl = http://127.0.0.1:9/{query}\n\n[maps]\na = dcterms:title");
        $templateId = $this->createTemplate('Autofiller unreachable', [
            'autofillers' => ['generic:json = user:' . $filename],
        ])->id();
        try {
            $this->dispatch('/admin/autofiller', 'GET', [
                'service' => 'generic:json',
                'template' => $templateId,
                'q' => 'x',
            ], true);
            $this->assertResponseStatusCode(502);
        } finally {
            @unlink($userPath . '/' . $filename);
        }
    }

    public function testEmptyQuery(): void
    {
        $this->dispatch('/admin/autofiller', 'GET', [
            'service' => 'idref:person',
            'template' => $this->templateId,
        ], true);
        $json = json_decode($this->getResponse()->getBody(), true);
        $this->assertSame('fail', $json['status']);
    }

    public function testSettingsRequiresXmlHttpRequest(): void
    {
        $this->dispatch('/admin/autofiller/settings', 'GET', [
            'service' => 'idref:person',
            'template' => $this->templateId,
        ]);
        $this->assertResponseStatusCode(404);
    }
}
