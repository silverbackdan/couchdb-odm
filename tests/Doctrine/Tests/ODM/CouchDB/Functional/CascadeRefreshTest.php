<?php

namespace Doctrine\Tests\ODM\CouchDB\Functional;

use Doctrine\ODM\CouchDB\Mapping\ClassMetadata;

class CascadeRefreshTest extends \Doctrine\Tests\ODM\CouchDB\CouchDBFunctionalTestCase
{
    /**
     * @var DocumentManager
     */
    private $dm;

    public function setUp()
    {
        $this->markTestSkipped('Cascade refresh does not work yet.');

        $this->dm = $this->createDocumentManager();

        $class = $this->dm->getClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $class->associationsMappings['groups']['cascade'] = ClassMetadata::CASCADE_REFRESH;

        $class = $this->dm->getClassMetadata('Doctrine\Tests\Models\CMS\CmsGroup');
        $class->associationsMappings['users']['cascade'] = ClassMetadata::CASCADE_REFRESH;

        $class = $this->dm->getClassMetadata('Doctrine\Tests\Models\CMS\CmsArticle');
        $class->associationsMappings['user']['cascade'] = ClassMetadata::CASCADE_REFRESH;
    }

    public function testCascadeRefresh()
    {
        $group1 = new \Doctrine\Tests\Models\CMS\CmsGroup();
        $group1->name = "Test!";

        $user = new \Doctrine\Tests\Models\CMS\CmsUser();
        $user->username = "beberlei";
        $user->name = "Benjamin";
        $user->addGroup($group1);

        $this->dm->persist($user);
        $this->dm->persist($group1);

        $this->dm->flush();

        $this->assertCount(1, $user->groups);

        $group1->name = "Test2";
        $user->username = "beberlei2";

        $this->dm->refresh($user);

        $this->assertEquals("beberlei", $user->username);
        $this->assertEquals("Test!", $group1->name);
    }
}
