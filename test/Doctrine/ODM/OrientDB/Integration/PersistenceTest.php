<?php

namespace test\Doctrine\ODM\OrientDB\Integration;


use Doctrine\ODM\OrientDB\Manager;
use test\Integration\Document\Country;
use test\PHPUnit\TestCase;

class PersistenceTest extends TestCase
{
    /**
     * @var Manager
     */
    protected $manager;

    protected function setUp()
    {
        $this->manager = $this->createManager();
    }

    public function testPersistDocument()
    {
        $document       = new Country();
        $document->name = 'SinglePersistTest';

        $this->manager->persist($document);
        $this->manager->flush();
        $this->manager->clear();
        $this->assertNotNull($document->getRid());

        $proxy = $this->manager->find($document->getRid());
        $this->assertEquals('SinglePersistTest', $proxy->name);

        return $document->getRid();
    }

    /**
     * @depends testPersistDocument
     * @param $rid
     */
    public function testUpdateDocument($rid)
    {
        $document = $this->manager->find($rid);
        $document->name = 'SingleUpdateTest';

        unset($document);
        $this->manager->flush();
        $this->manager->clear();

        $proxy = $this->manager->find($rid);
        $this->assertEquals('SingleUpdateTest', $proxy->name);

        return $rid;
    }

    /**
     * @depends testUpdateDocument
     * @param $rid
     */
    public function testDeleteDocument($rid)
    {
        $document = $this->manager->find($rid);
        $this->manager->remove($document);
        $this->manager->flush();
        unset($document);
        $this->manager->clear();

        $this->assertNull($this->manager->find($rid));
    }
} 