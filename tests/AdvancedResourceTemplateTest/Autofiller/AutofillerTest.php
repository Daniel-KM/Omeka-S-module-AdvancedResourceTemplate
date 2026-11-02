<?php declare(strict_types=1);

namespace AdvancedResourceTemplateTest\Autofiller;

use AdvancedResourceTemplate\Autofiller\GenericAutofiller;
use AdvancedResourceTemplate\Autofiller\GeonamesAutofiller;
use AdvancedResourceTemplate\Autofiller\IdRefAutofiller;
use AdvancedResourceTemplateTest\AdvancedResourceTemplateTestTrait;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Client\Adapter\Test as TestAdapter;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests of the autofillers with responses of the remote services.
 *
 * @group mapper
 */
class AutofillerTest extends AbstractHttpControllerTestCase
{
    use AdvancedResourceTemplateTestTrait;

    /**
     * @var TestAdapter
     */
    protected $adapter;

    public function setUp(): void
    {
        parent::setUp();
        if (!$this->getServiceLocator()->has('Mapper\Mapper')) {
            $this->markTestSkipped('Mapper module not available');
        }
        $this->loginAdmin();
        $this->adapter = new TestAdapter();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testGeonames(): void
    {
        $this->adapter->setResponse($this->response(json_encode(['geonames' => [[
            'geonameId' => 2983990,
            'name' => 'Rennes',
            'toponymName' => 'Rennes',
            'countryName' => 'France',
            'lat' => '48.11',
            'lng' => '-1.67',
        ]]])));

        $results = $this->autofiller(GeonamesAutofiller::class, ['service' => 'geonames'])
            ->getResults('Rennes', 'fr_FR');

        $this->assertCount(1, $results);
        $this->assertSame('http://www.geonames.org/2983990', $results[0]['info']['uri']);
        $this->assertSame('Rennes', $results[0]['info']['label']);
        $data = $results[0]['data'];
        $this->assertSame('Rennes', $data['dcterms:title'][0]['@value']);
        $this->assertSame('http://www.geonames.org/2983990', $data['dcterms:identifier'][0]['@id']);
        $this->assertSame('France', $data['dcterms:isPartOf'][0]['@value']);
        $this->assertSame('48.11/-1.67', $data['dcterms:spatial'][0]['@value']);
    }

    public function testGeonamesUnavailable(): void
    {
        $this->adapter->setResponse("HTTP/1.1 503 Service Unavailable\r\n\r\n");
        $results = $this->autofiller(GeonamesAutofiller::class, ['service' => 'geonames'])
            ->getResults('Rennes');
        $this->assertNull($results);
    }

    public function testIdRefFetchesEachRecord(): void
    {
        $this->adapter->setResponse($this->response(json_encode(['response' => ['docs' => [
            ['ppn_z' => '027715078', 'affcourt_z' => 'Hugo, Victor (1802-1885)'],
        ]]])));
        $this->adapter->addResponse($this->response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <record>
                <controlfield tag="003">http://www.idref.fr/027715078</controlfield>
                <datafield tag="900" ind1=" " ind2=" "><subfield code="a">Hugo, Victor (1802-1885)</subfield></datafield>
            </record>
            XML, 'application/xml'));

        $autofiller = $this->autofiller(IdRefAutofiller::class, ['service' => 'idref', 'sub' => 'person']);
        $this->assertSame('IdRef: Person names', $autofiller->getLabel());

        $results = $autofiller->getResults('hugo victor');
        $this->assertCount(1, $results);
        $this->assertSame('https://www.idref.fr/027715078', $results[0]['info']['uri']);
        $this->assertSame('Hugo, Victor (1802-1885)', $results[0]['info']['label']);
        $data = $results[0]['data'];
        $this->assertSame('Hugo, Victor (1802-1885)', $data['dcterms:title'][0]['@value']);
        $this->assertSame('http://www.idref.fr/027715078', $data['bibo:uri'][0]['@id']);
    }

    public function testIdRefUnknownSubService(): void
    {
        $results = $this->autofiller(IdRefAutofiller::class, ['service' => 'idref', 'sub' => 'unknown'])
            ->getResults('hugo');
        $this->assertNull($results);
    }

    public function testGenericJsonWithCustomMapping(): void
    {
        $mapping = <<<'INI'
            [info]
            label = My api
            querier = jsdot

            [params]
            url = https://example.org/api
            query = "search={query}"
            resources_root = data.list
            label = name

            [maps]
            name = dcterms:title
            id = dcterms:identifier
            INI;
        $this->adapter->setResponse($this->response(json_encode(['data' => ['list' => [
            ['id' => 'a1', 'name' => 'First'],
            ['id' => 'a2', 'name' => 'Second'],
        ]]])));

        $autofiller = $this->autofiller(GenericAutofiller::class, [
            'service' => 'generic',
            'sub' => 'json',
            'mapping' => $mapping,
        ]);
        $this->assertSame('My api', $autofiller->getLabel());

        $results = $autofiller->getResults('fir st');
        $this->assertCount(2, $results);
        $this->assertSame('First', $results[0]['value']);
        $this->assertSame('a2', $results[1]['data']['dcterms:identifier'][0]['@value']);
    }

    public function testGenericWithoutUrl(): void
    {
        $results = $this->autofiller(GenericAutofiller::class, [
            'service' => 'generic',
            'sub' => 'json',
            'mapping' => "[maps]\nname = dcterms:title",
        ])->getResults('x');
        $this->assertNull($results);
    }

    protected function autofiller(string $class, array $options)
    {
        $options['key'] = 'test:' . $this->getName();
        /** @var \AdvancedResourceTemplate\Autofiller\AbstractAutofiller $autofiller */
        $autofiller = new $class($this->getServiceLocator(), $options);
        return $autofiller->setHttpClient(new HttpClient(null, ['adapter' => $this->adapter]));
    }

    protected function response(string $body, string $mediaType = 'application/json'): string
    {
        return "HTTP/1.1 200 OK\r\nContent-Type: $mediaType\r\n\r\n" . $body;
    }
}
