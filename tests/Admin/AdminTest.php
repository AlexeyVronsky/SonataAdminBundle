<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Tests\Admin;

use Doctrine\Common\Collections\Collection;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use PHPUnit\Framework\TestCase;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Admin\AbstractAdminExtension;
use Sonata\AdminBundle\Admin\AdminExtensionInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Admin\BreadcrumbsBuilder;
use Sonata\AdminBundle\Admin\BreadcrumbsBuilderInterface;
use Sonata\AdminBundle\Admin\FieldDescriptionInterface;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Builder\DatagridBuilderInterface;
use Sonata\AdminBundle\Builder\FormContractorInterface;
use Sonata\AdminBundle\Builder\ListBuilderInterface;
use Sonata\AdminBundle\Builder\RouteBuilderInterface;
use Sonata\AdminBundle\Builder\ShowBuilderInterface;
use Sonata\AdminBundle\Datagrid\DatagridInterface;
use Sonata\AdminBundle\Datagrid\PagerInterface;
use Sonata\AdminBundle\Model\AuditManagerInterface;
use Sonata\AdminBundle\Model\ModelManagerInterface;
use Sonata\AdminBundle\Route\DefaultRouteGenerator;
use Sonata\AdminBundle\Route\PathInfoBuilder;
use Sonata\AdminBundle\Route\RouteGeneratorInterface;
use Sonata\AdminBundle\Route\RoutesCache;
use Sonata\AdminBundle\Security\Handler\AclSecurityHandlerInterface;
use Sonata\AdminBundle\Security\Handler\SecurityHandlerInterface;
use Sonata\AdminBundle\Tests\Fixtures\Admin\CommentAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\CommentVoteAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\CommentWithCustomRouteAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\FieldDescription;
use Sonata\AdminBundle\Tests\Fixtures\Admin\FilteredAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\ModelAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\PostAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\PostWithCustomRouteAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Admin\TagAdmin;
use Sonata\AdminBundle\Tests\Fixtures\Bundle\Entity\BlogPost;
use Sonata\AdminBundle\Tests\Fixtures\Bundle\Entity\Comment;
use Sonata\AdminBundle\Tests\Fixtures\Bundle\Entity\Post;
use Sonata\AdminBundle\Tests\Fixtures\Bundle\Entity\Tag;
use Sonata\AdminBundle\Tests\Fixtures\Entity\FooToString;
use Sonata\AdminBundle\Tests\Fixtures\Entity\FooToStringNull;
use Sonata\AdminBundle\Translator\LabelTranslatorStrategyInterface;
use Sonata\CoreBundle\Model\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AdminTest extends TestCase
{
    protected $cacheTempFolder;

    public function setUp()
    {
        $this->cacheTempFolder = sys_get_temp_dir().'/sonata_test_route';

        exec('rm -rf '.$this->cacheTempFolder);
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::__construct
     */
    public function testConstructor()
    {
        $class = 'Application\Sonata\NewsBundle\Entity\Post';
        $baseControllerName = 'SonataNewsBundle:PostAdmin';

        $admin = new PostAdmin('sonata.post.admin.post', $class, $baseControllerName);
        $this->assertInstanceOf(AbstractAdmin::class, $admin);
        $this->assertSame($class, $admin->getClass());
        $this->assertSame($baseControllerName, $admin->getBaseControllerName());
    }

    public function testGetClass()
    {
        $class = Post::class;
        $baseControllerName = 'SonataNewsBundle:PostAdmin';

        $admin = new PostAdmin('sonata.post.admin.post', $class, $baseControllerName);

        $admin->setModelManager($this->getMockForAbstractClass(ModelManagerInterface::class));

        $admin->setSubject(new BlogPost());
        $this->assertSame(BlogPost::class, $admin->getClass());

        $admin->setSubClasses(['foo']);
        $this->assertSame(BlogPost::class, $admin->getClass());

        $admin->setSubject(null);
        $admin->setSubClasses([]);
        $this->assertSame($class, $admin->getClass());

        $admin->setSubClasses(['foo' => 'bar']);
        $admin->setRequest(new Request(['subclass' => 'foo']));
        $this->assertSame('bar', $admin->getClass());
    }

    public function testGetClassException()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Feature not implemented: an embedded admin cannot have subclass');

        $class = 'Application\Sonata\NewsBundle\Entity\Post';
        $baseControllerName = 'SonataNewsBundle:PostAdmin';

        $admin = new PostAdmin('sonata.post.admin.post', $class, $baseControllerName);
        $admin->setParentFieldDescription(new FieldDescription());
        $admin->setSubClasses(['foo' => 'bar']);
        $admin->setRequest(new Request(['subclass' => 'foo']));
        $admin->getClass();
    }

    public function testCheckAccessThrowsExceptionOnMadeUpAction()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $this->expectException(
            \InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'Action "made-up" could not be found'
        );
        $admin->checkAccess('made-up');
    }

    public function testCheckAccessThrowsAccessDeniedException()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $securityHandler = $this->prophesize(SecurityHandlerInterface::class);
        $securityHandler->isGranted($admin, 'CUSTOM_ROLE', $admin)->willReturn(true);
        $securityHandler->isGranted($admin, 'EXTRA_CUSTOM_ROLE', $admin)->willReturn(false);
        $customExtension = $this->prophesize(AbstractAdminExtension::class);
        $customExtension->getAccessMapping($admin)->willReturn(
            ['custom_action' => ['CUSTOM_ROLE', 'EXTRA_CUSTOM_ROLE']]
        );
        $admin->addExtension($customExtension->reveal());
        $admin->setSecurityHandler($securityHandler->reveal());
        $this->expectException(
            AccessDeniedException::class
        );
        $this->expectExceptionMessage(
            'Access Denied to the action custom_action and role EXTRA_CUSTOM_ROLE'
        );
        $admin->checkAccess('custom_action');
    }

    public function testHasAccessOnMadeUpAction()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );

        $this->assertFalse($admin->hasAccess('made-up'));
    }

    public function testHasAccess()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $securityHandler = $this->prophesize(SecurityHandlerInterface::class);
        $securityHandler->isGranted($admin, 'CUSTOM_ROLE', $admin)->willReturn(true);
        $securityHandler->isGranted($admin, 'EXTRA_CUSTOM_ROLE', $admin)->willReturn(false);
        $customExtension = $this->prophesize(AbstractAdminExtension::class);
        $customExtension->getAccessMapping($admin)->willReturn(
            ['custom_action' => ['CUSTOM_ROLE', 'EXTRA_CUSTOM_ROLE']]
        );
        $admin->addExtension($customExtension->reveal());
        $admin->setSecurityHandler($securityHandler->reveal());

        $this->assertFalse($admin->hasAccess('custom_action'));
    }

    public function testHasAccessAllowsAccess()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $securityHandler = $this->prophesize(SecurityHandlerInterface::class);
        $securityHandler->isGranted($admin, 'CUSTOM_ROLE', $admin)->willReturn(true);
        $securityHandler->isGranted($admin, 'EXTRA_CUSTOM_ROLE', $admin)->willReturn(true);
        $customExtension = $this->prophesize(AbstractAdminExtension::class);
        $customExtension->getAccessMapping($admin)->willReturn(
            ['custom_action' => ['CUSTOM_ROLE', 'EXTRA_CUSTOM_ROLE']]
        );
        $admin->addExtension($customExtension->reveal());
        $admin->setSecurityHandler($securityHandler->reveal());

        $this->assertTrue($admin->hasAccess('custom_action'));
    }

    public function testHasAccessAllowsAccessEditAction()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $securityHandler = $this->prophesize(SecurityHandlerInterface::class);
        $securityHandler->isGranted($admin, 'EDIT_ROLE', $admin)->willReturn(true);
        $customExtension = $this->prophesize(AbstractAdminExtension::class);
        $customExtension->getAccessMapping($admin)->willReturn(
            ['edit_action' => ['EDIT_ROLE']]
        );
        $admin->addExtension($customExtension->reveal());
        $admin->setSecurityHandler($securityHandler->reveal());

        $this->assertTrue($admin->hasAccess('edit_action'));
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::hasChild
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::addChild
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getChild
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::isChild
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::hasChildren
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getChildren
     */
    public function testChildren()
    {
        $postAdmin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $this->assertFalse($postAdmin->hasChildren());
        $this->assertFalse($postAdmin->hasChild('comment'));

        $commentAdmin = new CommentAdmin('sonata.post.admin.comment', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentAdmin');
        $postAdmin->addChild($commentAdmin);
        $this->assertTrue($postAdmin->hasChildren());
        $this->assertTrue($postAdmin->hasChild('sonata.post.admin.comment'));

        $this->assertSame('sonata.post.admin.comment', $postAdmin->getChild('sonata.post.admin.comment')->getCode());
        $this->assertSame('sonata.post.admin.post|sonata.post.admin.comment', $postAdmin->getChild('sonata.post.admin.comment')->getBaseCodeRoute());
        $this->assertSame($postAdmin, $postAdmin->getChild('sonata.post.admin.comment')->getParent());

        $this->assertFalse($postAdmin->isChild());
        $this->assertTrue($commentAdmin->isChild());

        $this->assertSame(['sonata.post.admin.comment' => $commentAdmin], $postAdmin->getChildren());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::configure
     */
    public function testConfigure()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $this->assertNotNull($admin->getUniqid());

        $admin->initialize();
        $this->assertNotNull($admin->getUniqid());
        $this->assertSame('Post', $admin->getClassnameLabel());

        $admin = new CommentAdmin('sonata.post.admin.comment', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentAdmin');
        $admin->setClassnameLabel('postcomment');

        $admin->initialize();
        $this->assertSame('postcomment', $admin->getClassnameLabel());
    }

    public function testConfigureWithValidParentAssociationMapping()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setParentAssociationMapping('Category');

        $admin->initialize();
        $this->assertSame('Category', $admin->getParentAssociationMapping());
    }

    public function provideGetBaseRoutePattern()
    {
        return [
            [
                'Application\Sonata\NewsBundle\Entity\Post',
                '/sonata/news/post',
            ],
            [
                'Application\Sonata\NewsBundle\Document\Post',
                '/sonata/news/post',
            ],
            [
                'MyApplication\MyBundle\Entity\Post',
                '/myapplication/my/post',
            ],
            [
                'MyApplication\MyBundle\Entity\Post\Category',
                '/myapplication/my/post-category',
            ],
            [
                'MyApplication\MyBundle\Entity\Product\Category',
                '/myapplication/my/product-category',
            ],
            [
                'MyApplication\MyBundle\Entity\Other\Product\Category',
                '/myapplication/my/other-product-category',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Document\Menu',
                '/cmf/foo/menu',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\Phpcr\Menu',
                '/cmf/foo/menu',
            ],
            [
                'Symfony\Bundle\BarBarBundle\Doctrine\Phpcr\Menu',
                '/symfony/barbar/menu',
            ],
            [
                'Symfony\Bundle\BarBarBundle\Doctrine\Phpcr\Menu\Item',
                '/symfony/barbar/menu-item',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\Orm\Menu',
                '/cmf/foo/menu',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\MongoDB\Menu',
                '/cmf/foo/menu',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\CouchDB\Menu',
                '/cmf/foo/menu',
            ],
            [
                'AppBundle\Entity\User',
                '/app/user',
            ],
            [
                'App\Entity\User',
                '/app/user',
            ],
        ];
    }

    /**
     * @dataProvider provideGetBaseRoutePattern
     */
    public function testGetBaseRoutePattern($objFqn, $expected)
    {
        $admin = new PostAdmin('sonata.post.admin.post', $objFqn, 'SonataNewsBundle:PostAdmin');
        $this->assertSame($expected, $admin->getBaseRoutePattern());
    }

    /**
     * @dataProvider provideGetBaseRoutePattern
     */
    public function testGetBaseRoutePatternWithChildAdmin($objFqn, $expected)
    {
        $postAdmin = new PostAdmin('sonata.post.admin.post', $objFqn, 'SonataNewsBundle:PostAdmin');
        $commentAdmin = new CommentAdmin('sonata.post.admin.comment', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentAdmin');
        $commentAdmin->setParent($postAdmin);

        $this->assertSame($expected.'/{id}/comment', $commentAdmin->getBaseRoutePattern());
    }

    /**
     * @dataProvider provideGetBaseRoutePattern
     */
    public function testGetBaseRoutePatternWithTwoNestedChildAdmin($objFqn, $expected)
    {
        $postAdmin = new PostAdmin('sonata.post.admin.post', $objFqn, 'SonataNewsBundle:PostAdmin');
        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );
        $commentAdmin->setParent($postAdmin);
        $commentVoteAdmin->setParent($commentAdmin);

        $this->assertSame($expected.'/{id}/comment/{childId}/commentvote', $commentVoteAdmin->getBaseRoutePattern());
    }

    public function testGetBaseRoutePatternWithSpecifedPattern()
    {
        $postAdmin = new PostWithCustomRouteAdmin('sonata.post.admin.post_with_custom_route', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostWithCustomRouteAdmin');

        $this->assertSame('/post-custom', $postAdmin->getBaseRoutePattern());
    }

    public function testGetBaseRoutePatternWithChildAdminAndWithSpecifedPattern()
    {
        $postAdmin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $commentAdmin = new CommentWithCustomRouteAdmin('sonata.post.admin.comment_with_custom_route', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentWithCustomRouteAdmin');
        $commentAdmin->setParent($postAdmin);

        $this->assertSame('/sonata/news/post/{id}/comment-custom', $commentAdmin->getBaseRoutePattern());
    }

    public function testGetBaseRoutePatternWithUnreconizedClassname()
    {
        $this->expectException(\RuntimeException::class);

        $admin = new PostAdmin('sonata.post.admin.post', 'News\Thing\Post', 'SonataNewsBundle:PostAdmin');
        $admin->getBaseRoutePattern();
    }

    public function provideGetBaseRouteName()
    {
        return [
            [
                'Application\Sonata\NewsBundle\Entity\Post',
                'admin_sonata_news_post',
            ],
            [
                'Application\Sonata\NewsBundle\Document\Post',
                'admin_sonata_news_post',
            ],
            [
                'MyApplication\MyBundle\Entity\Post',
                'admin_myapplication_my_post',
            ],
            [
                'MyApplication\MyBundle\Entity\Post\Category',
                'admin_myapplication_my_post_category',
            ],
            [
                'MyApplication\MyBundle\Entity\Product\Category',
                'admin_myapplication_my_product_category',
            ],
            [
                'MyApplication\MyBundle\Entity\Other\Product\Category',
                'admin_myapplication_my_other_product_category',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Document\Menu',
                'admin_cmf_foo_menu',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\Phpcr\Menu',
                'admin_cmf_foo_menu',
            ],
            [
                'Symfony\Bundle\BarBarBundle\Doctrine\Phpcr\Menu',
                'admin_symfony_barbar_menu',
            ],
            [
                'Symfony\Bundle\BarBarBundle\Doctrine\Phpcr\Menu\Item',
                'admin_symfony_barbar_menu_item',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\Orm\Menu',
                'admin_cmf_foo_menu',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\MongoDB\Menu',
                'admin_cmf_foo_menu',
            ],
            [
                'Symfony\Cmf\Bundle\FooBundle\Doctrine\CouchDB\Menu',
                'admin_cmf_foo_menu',
            ],
            [
                'AppBundle\Entity\User',
                'admin_app_user',
            ],
            [
                'App\Entity\User',
                'admin_app_user',
            ],
        ];
    }

    /**
     * @dataProvider provideGetBaseRouteName
     */
    public function testGetBaseRouteName($objFqn, $expected)
    {
        $admin = new PostAdmin('sonata.post.admin.post', $objFqn, 'SonataNewsBundle:PostAdmin');

        $this->assertSame($expected, $admin->getBaseRouteName());
    }

    /**
     * @dataProvider provideGetBaseRouteName
     */
    public function testGetBaseRouteNameWithChildAdmin($objFqn, $expected)
    {
        $routeGenerator = new DefaultRouteGenerator(
            $this->createMock(RouterInterface::class),
            new RoutesCache($this->cacheTempFolder, true)
        );

        $container = new Container();
        $pool = new Pool($container, 'Sonata Admin', '/path/to/pic.png');

        $pathInfo = new PathInfoBuilder($this->createMock(AuditManagerInterface::class));
        $postAdmin = new PostAdmin('sonata.post.admin.post', $objFqn, 'SonataNewsBundle:PostAdmin');
        $container->set('sonata.post.admin.post', $postAdmin);
        $postAdmin->setConfigurationPool($pool);
        $postAdmin->setRouteBuilder($pathInfo);
        $postAdmin->setRouteGenerator($routeGenerator);
        $postAdmin->initialize();

        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $container->set('sonata.post.admin.comment', $commentAdmin);
        $commentAdmin->setConfigurationPool($pool);
        $commentAdmin->setRouteBuilder($pathInfo);
        $commentAdmin->setRouteGenerator($routeGenerator);
        $commentAdmin->initialize();

        $postAdmin->addChild($commentAdmin);

        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );
        $container->set('sonata.post.admin.comment_vote', $commentVoteAdmin);
        $commentVoteAdmin->setConfigurationPool($pool);
        $commentVoteAdmin->setRouteBuilder($pathInfo);
        $commentVoteAdmin->setRouteGenerator($routeGenerator);
        $commentVoteAdmin->initialize();

        $commentAdmin->addChild($commentVoteAdmin);
        $pool->setAdminServiceIds([
            'sonata.post.admin.post',
            'sonata.post.admin.comment',
            'sonata.post.admin.comment_vote',
        ]);

        $this->assertSame($expected.'_comment', $commentAdmin->getBaseRouteName());

        $this->assertTrue($postAdmin->hasRoute('show'));
        $this->assertTrue($postAdmin->hasRoute('sonata.post.admin.post.show'));
        $this->assertTrue($postAdmin->hasRoute('sonata.post.admin.post|sonata.post.admin.comment.show'));
        $this->assertTrue($postAdmin->hasRoute('sonata.post.admin.post|sonata.post.admin.comment|sonata.post.admin.comment_vote.show'));
        $this->assertTrue($postAdmin->hasRoute('sonata.post.admin.comment.list'));
        $this->assertTrue($postAdmin->hasRoute('sonata.post.admin.comment|sonata.post.admin.comment_vote.list'));
        $this->assertFalse($postAdmin->hasRoute('sonata.post.admin.post|sonata.post.admin.comment.edit'));
        $this->assertFalse($commentAdmin->hasRoute('edit'));

        /*
         * Test the route name from request
         */
        $postListRequest = new Request(
            [],
            [],
            [
                '_route' => $postAdmin->getBaseRouteName().'_list',
            ]
        );

        $postAdmin->setRequest($postListRequest);
        $commentAdmin->setRequest($postListRequest);

        $this->assertTrue($postAdmin->isCurrentRoute('list'));
        $this->assertFalse($postAdmin->isCurrentRoute('create'));
        $this->assertFalse($commentAdmin->isCurrentRoute('list'));
        $this->assertFalse($commentVoteAdmin->isCurrentRoute('list'));
        $this->assertTrue($commentAdmin->isCurrentRoute('list', 'sonata.post.admin.post'));
        $this->assertFalse($commentAdmin->isCurrentRoute('edit', 'sonata.post.admin.post'));
        $this->assertTrue($commentVoteAdmin->isCurrentRoute('list', 'sonata.post.admin.post'));
        $this->assertFalse($commentVoteAdmin->isCurrentRoute('edit', 'sonata.post.admin.post'));
    }

    public function testGetBaseRouteNameWithUnreconizedClassname()
    {
        $this->expectException(\RuntimeException::class);

        $admin = new PostAdmin('sonata.post.admin.post', 'News\Thing\Post', 'SonataNewsBundle:PostAdmin');
        $admin->getBaseRouteName();
    }

    public function testGetBaseRouteNameWithSpecifiedName()
    {
        $postAdmin = new PostWithCustomRouteAdmin('sonata.post.admin.post_with_custom_route', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame('post_custom', $postAdmin->getBaseRouteName());
    }

    public function testGetBaseRouteNameWithChildAdminAndWithSpecifiedName()
    {
        $postAdmin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $commentAdmin = new CommentWithCustomRouteAdmin('sonata.post.admin.comment_with_custom_route', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentWithCustomRouteAdmin');
        $commentAdmin->setParent($postAdmin);

        $this->assertSame('admin_sonata_news_post_comment_custom', $commentAdmin->getBaseRouteName());
    }

    public function testGetBaseRouteNameWithTwoNestedChildAdminAndWithSpecifiedName()
    {
        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $commentAdmin = new CommentWithCustomRouteAdmin(
            'sonata.post.admin.comment_with_custom_route',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentWithCustomRouteAdmin'
        );
        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );
        $commentAdmin->setParent($postAdmin);
        $commentVoteAdmin->setParent($commentAdmin);

        $this->assertSame('admin_sonata_news_post_comment_custom_commentvote', $commentVoteAdmin->getBaseRouteName());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::setUniqid
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getUniqid
     */
    public function testUniqid()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $uniqid = uniqid();
        $admin->setUniqid($uniqid);

        $this->assertSame($uniqid, $admin->getUniqid());
    }

    public function testToString()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $s = new \stdClass();

        $this->assertNotEmpty($admin->toString($s));

        $s = new FooToString();
        $this->assertSame('salut', $admin->toString($s));

        // To string method is implemented, but returns null
        $s = new FooToStringNull();
        $this->assertNotEmpty($admin->toString($s));

        $this->assertSame('', $admin->toString(false));
    }

    public function testIsAclEnabled()
    {
        $postAdmin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertFalse($postAdmin->isAclEnabled());

        $commentAdmin = new CommentAdmin('sonata.post.admin.comment', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentAdmin');
        $commentAdmin->setSecurityHandler($this->createMock(AclSecurityHandlerInterface::class));
        $this->assertTrue($commentAdmin->isAclEnabled());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getSubClasses
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getSubClass
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::setSubClasses
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::hasSubClass
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::hasActiveSubClass
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getActiveSubClass
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getActiveSubclassCode
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getClass
     */
    public function testSubClass()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            Post::class,
            'SonataNewsBundle:PostAdmin'
        );
        $this->assertFalse($admin->hasSubClass('test'));
        $this->assertFalse($admin->hasActiveSubClass());
        $this->assertCount(0, $admin->getSubClasses());
        $this->assertNull($admin->getActiveSubClass());
        $this->assertNull($admin->getActiveSubclassCode());
        $this->assertSame(Post::class, $admin->getClass());

        // Just for the record, if there is no inheritance set, the getSubject is not used
        // the getSubject can also lead to some issue
        $admin->setSubject(new BlogPost());
        $this->assertSame(BlogPost::class, $admin->getClass());

        $admin->setSubClasses([
            'extended1' => 'NewsBundle\Entity\PostExtended1',
            'extended2' => 'NewsBundle\Entity\PostExtended2',
        ]);
        $this->assertFalse($admin->hasSubClass('test'));
        $this->assertTrue($admin->hasSubClass('extended1'));
        $this->assertFalse($admin->hasActiveSubClass());
        $this->assertCount(2, $admin->getSubClasses());
        $this->assertNull($admin->getActiveSubClass());
        $this->assertNull($admin->getActiveSubclassCode());
        $this->assertSame(
            BlogPost::class,
            $admin->getClass(),
            'When there is no subclass in the query the class parameter should be returned'
        );

        $request = new Request(['subclass' => 'extended1']);
        $admin->setRequest($request);
        $this->assertFalse($admin->hasSubClass('test'));
        $this->assertTrue($admin->hasSubClass('extended1'));
        $this->assertTrue($admin->hasActiveSubClass());
        $this->assertCount(2, $admin->getSubClasses());
        $this->assertSame(
            'NewsBundle\Entity\PostExtended1',
            $admin->getActiveSubClass(),
            'It should return the curently active sub class.'
        );
        $this->assertSame('extended1', $admin->getActiveSubclassCode());
        $this->assertSame(
            'NewsBundle\Entity\PostExtended1',
            $admin->getClass(),
            'getClass() should return the name of the sub class when passed through a request query parameter.'
        );

        $request->query->set('subclass', 'inject');
        $this->assertNull($admin->getActiveSubclassCode());
    }

    public function testNonExistantSubclass()
    {
        $this->expectException(\RuntimeException::class);

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setModelManager($this->getMockForAbstractClass(ModelManagerInterface::class));

        $admin->setRequest(new Request(['subclass' => 'inject']));

        $admin->setSubClasses(['extended1' => 'NewsBundle\Entity\PostExtended1', 'extended2' => 'NewsBundle\Entity\PostExtended2']);

        $this->assertTrue($admin->hasActiveSubClass());

        $admin->getActiveSubClass();
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::hasActiveSubClass
     */
    public function testOnlyOneSubclassNeededToBeActive()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setSubClasses(['extended1' => 'NewsBundle\Entity\PostExtended1']);
        $request = new Request(['subclass' => 'extended1']);
        $admin->setRequest($request);
        $this->assertTrue($admin->hasActiveSubClass());
    }

    /**
     * @group legacy
     * @expectedDeprecation Method "Sonata\AdminBundle\Admin\AbstractAdmin::addSubClass" is deprecated since 3.x and will be removed in 4.0.
     */
    public function testAddSubClassIsDeprecated()
    {
        $admin = new PostAdmin(
            'sonata.post.admin.post',
            Post::class,
            'SonataNewsBundle:PostAdmin'
        );
        $admin->addSubClass('whatever');
    }

    public function testGetPerPageOptions()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame([16, 32, 64, 128, 192], $admin->getPerPageOptions());
        $admin->setPerPageOptions([500, 1000]);
        $this->assertSame([500, 1000], $admin->getPerPageOptions());
    }

    public function testGetLabelTranslatorStrategy()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getLabelTranslatorStrategy());

        $labelTranslatorStrategy = $this->createMock(LabelTranslatorStrategyInterface::class);
        $admin->setLabelTranslatorStrategy($labelTranslatorStrategy);
        $this->assertSame($labelTranslatorStrategy, $admin->getLabelTranslatorStrategy());
    }

    public function testGetRouteBuilder()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getRouteBuilder());

        $routeBuilder = $this->createMock(RouteBuilderInterface::class);
        $admin->setRouteBuilder($routeBuilder);
        $this->assertSame($routeBuilder, $admin->getRouteBuilder());
    }

    public function testGetMenuFactory()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getMenuFactory());

        $menuFactory = $this->createMock(FactoryInterface::class);
        $admin->setMenuFactory($menuFactory);
        $this->assertSame($menuFactory, $admin->getMenuFactory());
    }

    public function testGetExtensions()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame([], $admin->getExtensions());

        $adminExtension1 = $this->createMock(AdminExtensionInterface::class);
        $adminExtension2 = $this->createMock(AdminExtensionInterface::class);

        $admin->addExtension($adminExtension1);
        $admin->addExtension($adminExtension2);
        $this->assertSame([$adminExtension1, $adminExtension2], $admin->getExtensions());
    }

    public function testGetFilterTheme()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame([], $admin->getFilterTheme());

        $admin->setFilterTheme(['FooTheme']);
        $this->assertSame(['FooTheme'], $admin->getFilterTheme());
    }

    public function testGetFormTheme()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame([], $admin->getFormTheme());

        $admin->setFormTheme(['FooTheme']);

        $this->assertSame(['FooTheme'], $admin->getFormTheme());
    }

    public function testGetValidator()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getValidator());

        $validator = $this->getMockForAbstractClass(ValidatorInterface::class);

        $admin->setValidator($validator);
        $this->assertSame($validator, $admin->getValidator());
    }

    public function testGetSecurityHandler()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getSecurityHandler());

        $securityHandler = $this->createMock(SecurityHandlerInterface::class);
        $admin->setSecurityHandler($securityHandler);
        $this->assertSame($securityHandler, $admin->getSecurityHandler());
    }

    public function testGetSecurityInformation()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame([], $admin->getSecurityInformation());

        $securityInformation = [
            'GUEST' => ['VIEW', 'LIST'],
            'STAFF' => ['EDIT', 'LIST', 'CREATE'],
        ];

        $admin->setSecurityInformation($securityInformation);
        $this->assertSame($securityInformation, $admin->getSecurityInformation());
    }

    public function testGetManagerType()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getManagerType());

        $admin->setManagerType('foo_orm');
        $this->assertSame('foo_orm', $admin->getManagerType());
    }

    public function testGetModelManager()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getModelManager());

        $modelManager = $this->createMock(ModelManagerInterface::class);

        $admin->setModelManager($modelManager);
        $this->assertSame($modelManager, $admin->getModelManager());
    }

    /**
     * NEXT_MAJOR: remove this method.
     *
     * @group legacy
     */
    public function testGetBaseCodeRoute()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame('', $admin->getBaseCodeRoute());

        $admin->setBaseCodeRoute('foo');
        $this->assertSame('foo', $admin->getBaseCodeRoute());
    }

    // NEXT_MAJOR: uncomment this method.
    // public function testGetBaseCodeRoute()
    // {
    //     $postAdmin = new PostAdmin(
    //         'sonata.post.admin.post',
    //         'NewsBundle\Entity\Post',
    //         'SonataNewsBundle:PostAdmin'
    //     );
    //     $commentAdmin = new CommentAdmin(
    //         'sonata.post.admin.comment',
    //         'Application\Sonata\NewsBundle\Entity\Comment',
    //         'SonataNewsBundle:CommentAdmin'
    //     );
    //
    //     $this->assertSame($postAdmin->getCode(), $postAdmin->getBaseCodeRoute());
    //
    //     $postAdmin->addChild($commentAdmin);
    //
    //     $this->assertSame(
    //         'sonata.post.admin.post|sonata.post.admin.comment',
    //         $commentAdmin->getBaseCodeRoute()
    //     );
    // }

    public function testGetRouteGenerator()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getRouteGenerator());

        $routeGenerator = $this->createMock(RouteGeneratorInterface::class);

        $admin->setRouteGenerator($routeGenerator);
        $this->assertSame($routeGenerator, $admin->getRouteGenerator());
    }

    public function testGetConfigurationPool()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getConfigurationPool());

        $pool = $this->getMockBuilder(Pool::class)
            ->disableOriginalConstructor()
            ->getMock();

        $admin->setConfigurationPool($pool);
        $this->assertSame($pool, $admin->getConfigurationPool());
    }

    public function testGetShowBuilder()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getShowBuilder());

        $showBuilder = $this->createMock(ShowBuilderInterface::class);

        $admin->setShowBuilder($showBuilder);
        $this->assertSame($showBuilder, $admin->getShowBuilder());
    }

    public function testGetListBuilder()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getListBuilder());

        $listBuilder = $this->createMock(ListBuilderInterface::class);

        $admin->setListBuilder($listBuilder);
        $this->assertSame($listBuilder, $admin->getListBuilder());
    }

    public function testGetDatagridBuilder()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getDatagridBuilder());

        $datagridBuilder = $this->createMock(DatagridBuilderInterface::class);

        $admin->setDatagridBuilder($datagridBuilder);
        $this->assertSame($datagridBuilder, $admin->getDatagridBuilder());
    }

    public function testGetFormContractor()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getFormContractor());

        $formContractor = $this->createMock(FormContractorInterface::class);

        $admin->setFormContractor($formContractor);
        $this->assertSame($formContractor, $admin->getFormContractor());
    }

    public function testGetRequest()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertFalse($admin->hasRequest());

        $request = new Request();

        $admin->setRequest($request);
        $this->assertSame($request, $admin->getRequest());
        $this->assertTrue($admin->hasRequest());
    }

    public function testGetRequestWithException()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Request object has not been set');

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->getRequest();
    }

    public function testGetTranslationDomain()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame('messages', $admin->getTranslationDomain());

        $admin->setTranslationDomain('foo');
        $this->assertSame('foo', $admin->getTranslationDomain());
    }

    /**
     * @group legacy
     */
    public function testGetTranslator()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getTranslator());

        $translator = $this->createMock(TranslatorInterface::class);

        $admin->setTranslator($translator);
        $this->assertSame($translator, $admin->getTranslator());
    }

    public function testGetShowGroups()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertFalse($admin->getShowGroups());

        $groups = ['foo', 'bar', 'baz'];

        $admin->setShowGroups($groups);
        $this->assertSame($groups, $admin->getShowGroups());
    }

    public function testGetFormGroups()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertFalse($admin->getFormGroups());

        $groups = ['foo', 'bar', 'baz'];

        $admin->setFormGroups($groups);
        $this->assertSame($groups, $admin->getFormGroups());
    }

    public function testGetMaxPageLinks()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame(25, $admin->getMaxPageLinks());

        $admin->setMaxPageLinks(14);
        $this->assertSame(14, $admin->getMaxPageLinks());
    }

    public function testGetMaxPerPage()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame(32, $admin->getMaxPerPage());

        $admin->setMaxPerPage(94);
        $this->assertSame(94, $admin->getMaxPerPage());
    }

    public function testGetLabel()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getLabel());

        $admin->setLabel('FooLabel');
        $this->assertSame('FooLabel', $admin->getLabel());
    }

    public function testGetBaseController()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame('SonataNewsBundle:PostAdmin', $admin->getBaseControllerName());

        $admin->setBaseControllerName('SonataNewsBundle:FooAdmin');
        $this->assertSame('SonataNewsBundle:FooAdmin', $admin->getBaseControllerName());
    }

    public function testGetTemplates()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame([], $admin->getTemplates());

        $templates = [
            'list' => 'FooAdminBundle:CRUD:list.html.twig',
            'show' => 'FooAdminBundle:CRUD:show.html.twig',
            'edit' => 'FooAdminBundle:CRUD:edit.html.twig',
        ];

        $admin->setTemplates($templates);
        $this->assertSame($templates, $admin->getTemplates());
    }

    public function testGetTemplate1()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getTemplate('edit'));

        $admin->setTemplate('edit', 'FooAdminBundle:CRUD:edit.html.twig');
        $admin->setTemplate('show', 'FooAdminBundle:CRUD:show.html.twig');

        $this->assertSame('FooAdminBundle:CRUD:edit.html.twig', $admin->getTemplate('edit'));
        $this->assertSame('FooAdminBundle:CRUD:show.html.twig', $admin->getTemplate('show'));
    }

    public function testGetTemplate2()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertNull($admin->getTemplate('edit'));

        $templates = [
            'list' => 'FooAdminBundle:CRUD:list.html.twig',
            'show' => 'FooAdminBundle:CRUD:show.html.twig',
            'edit' => 'FooAdminBundle:CRUD:edit.html.twig',
        ];

        $admin->setTemplates($templates);

        $this->assertSame('FooAdminBundle:CRUD:edit.html.twig', $admin->getTemplate('edit'));
        $this->assertSame('FooAdminBundle:CRUD:show.html.twig', $admin->getTemplate('show'));
    }

    public function testGetIdParameter()
    {
        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );

        $this->assertSame('id', $postAdmin->getIdParameter());
        $this->assertFalse($postAdmin->isChild());

        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $commentAdmin->setParent($postAdmin);

        $this->assertTrue($commentAdmin->isChild());
        $this->assertSame('childId', $commentAdmin->getIdParameter());

        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );
        $commentVoteAdmin->setParent($commentAdmin);

        $this->assertTrue($commentVoteAdmin->isChild());
        $this->assertSame('childChildId', $commentVoteAdmin->getIdParameter());
    }

    public function testGetExportFormats()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame(['json', 'xml', 'csv', 'xls'], $admin->getExportFormats());
    }

    public function testGetUrlsafeIdentifier()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Acme\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $entity = new \stdClass();

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->expects($this->once())
            ->method('getUrlsafeIdentifier')
            ->with($this->equalTo($entity))
            ->will($this->returnValue('foo'));
        $admin->setModelManager($modelManager);

        $this->assertSame('foo', $admin->getUrlsafeIdentifier($entity));
    }

    public function testDeterminedPerPageValue()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Acme\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertFalse($admin->determinedPerPageValue('foo'));
        $this->assertFalse($admin->determinedPerPageValue(123));
        $this->assertTrue($admin->determinedPerPageValue(16));
        $this->assertTrue($admin->determinedPerPageValue(32));
        $this->assertTrue($admin->determinedPerPageValue(64));
        $this->assertTrue($admin->determinedPerPageValue(128));
        $this->assertTrue($admin->determinedPerPageValue(192));

        $admin->setPerPageOptions([101, 102, 103]);
        $this->assertFalse($admin->determinedPerPageValue(15));
        $this->assertFalse($admin->determinedPerPageValue(25));
        $this->assertFalse($admin->determinedPerPageValue(200));
        $this->assertTrue($admin->determinedPerPageValue(101));
        $this->assertTrue($admin->determinedPerPageValue(102));
        $this->assertTrue($admin->determinedPerPageValue(103));
    }

    public function testIsGranted()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Acme\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $entity = new \stdClass();

        $securityHandler = $this->createMock(AclSecurityHandlerInterface::class);
        $securityHandler->expects($this->any())
            ->method('isGranted')
            ->will($this->returnCallback(function (AdminInterface $adminIn, $attributes, $object = null) use ($admin, $entity) {
                if ($admin == $adminIn && 'FOO' == $attributes) {
                    if (($object == $admin) || ($object == $entity)) {
                        return true;
                    }
                }

                return false;
            }));

        $admin->setSecurityHandler($securityHandler);

        $this->assertTrue($admin->isGranted('FOO'));
        $this->assertTrue($admin->isGranted('FOO', $entity));
        $this->assertFalse($admin->isGranted('BAR'));
        $this->assertFalse($admin->isGranted('BAR', $entity));
    }

    public function testSupportsPreviewMode()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertFalse($admin->supportsPreviewMode());
    }

    public function testGetPermissionsShow()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame(['LIST'], $admin->getPermissionsShow(AbstractAdmin::CONTEXT_DASHBOARD));
        $this->assertSame(['LIST'], $admin->getPermissionsShow(AbstractAdmin::CONTEXT_MENU));
        $this->assertSame(['LIST'], $admin->getPermissionsShow('foo'));
    }

    public function testShowIn()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Acme\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $securityHandler = $this->createMock(AclSecurityHandlerInterface::class);
        $securityHandler->expects($this->any())
            ->method('isGranted')
            ->will($this->returnCallback(function (AdminInterface $adminIn, $attributes, $object = null) use ($admin) {
                if ($admin == $adminIn && $attributes == ['LIST']) {
                    return true;
                }

                return false;
            }));

        $admin->setSecurityHandler($securityHandler);

        $this->assertTrue($admin->showIn(AbstractAdmin::CONTEXT_DASHBOARD));
        $this->assertTrue($admin->showIn(AbstractAdmin::CONTEXT_MENU));
        $this->assertTrue($admin->showIn('foo'));
    }

    public function testGetObjectIdentifier()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'Acme\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame('sonata.post.admin.post', $admin->getObjectIdentifier());
    }

    /**
     * @group legacy
     */
    public function testTrans()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setTranslationDomain('fooMessageDomain');

        $translator = $this->createMock(TranslatorInterface::class);
        $admin->setTranslator($translator);

        $translator->expects($this->once())
            ->method('trans')
            ->with($this->equalTo('foo'), $this->equalTo([]), $this->equalTo('fooMessageDomain'))
            ->will($this->returnValue('fooTranslated'));

        $this->assertSame('fooTranslated', $admin->trans('foo'));
    }

    /**
     * @group legacy
     */
    public function testTransWithMessageDomain()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $translator = $this->createMock(TranslatorInterface::class);
        $admin->setTranslator($translator);

        $translator->expects($this->once())
            ->method('trans')
            ->with($this->equalTo('foo'), $this->equalTo(['name' => 'Andrej']), $this->equalTo('fooMessageDomain'))
            ->will($this->returnValue('fooTranslated'));

        $this->assertSame('fooTranslated', $admin->trans('foo', ['name' => 'Andrej'], 'fooMessageDomain'));
    }

    /**
     * @group legacy
     */
    public function testTransChoice()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setTranslationDomain('fooMessageDomain');

        $translator = $this->createMock(TranslatorInterface::class);
        $admin->setTranslator($translator);

        $translator->expects($this->once())
            ->method('transChoice')
            ->with($this->equalTo('foo'), $this->equalTo(2), $this->equalTo([]), $this->equalTo('fooMessageDomain'))
            ->will($this->returnValue('fooTranslated'));

        $this->assertSame('fooTranslated', $admin->transChoice('foo', 2));
    }

    /**
     * @group legacy
     */
    public function testTransChoiceWithMessageDomain()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $translator = $this->createMock(TranslatorInterface::class);
        $admin->setTranslator($translator);

        $translator->expects($this->once())
            ->method('transChoice')
            ->with($this->equalTo('foo'), $this->equalTo(2), $this->equalTo(['name' => 'Andrej']), $this->equalTo('fooMessageDomain'))
            ->will($this->returnValue('fooTranslated'));

        $this->assertSame('fooTranslated', $admin->transChoice('foo', 2, ['name' => 'Andrej'], 'fooMessageDomain'));
    }

    public function testSetPersistFilters()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertAttributeSame(false, 'persistFilters', $admin);
        $admin->setPersistFilters(true);
        $this->assertAttributeSame(true, 'persistFilters', $admin);
    }

    public function testGetRootCode()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame('sonata.post.admin.post', $admin->getRootCode());

        $parentAdmin = new PostAdmin('sonata.post.admin.post.parent', 'NewsBundle\Entity\PostParent', 'SonataNewsBundle:PostParentAdmin');
        $parentFieldDescription = $this->createMock(FieldDescriptionInterface::class);
        $parentFieldDescription->expects($this->once())
            ->method('getAdmin')
            ->will($this->returnValue($parentAdmin));

        $this->assertNull($admin->getParentFieldDescription());
        $admin->setParentFieldDescription($parentFieldDescription);
        $this->assertSame($parentFieldDescription, $admin->getParentFieldDescription());
        $this->assertSame('sonata.post.admin.post.parent', $admin->getRootCode());
    }

    public function testGetRoot()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertSame($admin, $admin->getRoot());

        $parentAdmin = new PostAdmin('sonata.post.admin.post.parent', 'NewsBundle\Entity\PostParent', 'SonataNewsBundle:PostParentAdmin');
        $parentFieldDescription = $this->createMock(FieldDescriptionInterface::class);
        $parentFieldDescription->expects($this->once())
            ->method('getAdmin')
            ->will($this->returnValue($parentAdmin));

        $this->assertNull($admin->getParentFieldDescription());
        $admin->setParentFieldDescription($parentFieldDescription);
        $this->assertSame($parentFieldDescription, $admin->getParentFieldDescription());
        $this->assertSame($parentAdmin, $admin->getRoot());
    }

    public function testGetExportFields()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->expects($this->once())
            ->method('getExportFields')
            ->with($this->equalTo('NewsBundle\Entity\Post'))
            ->will($this->returnValue(['foo', 'bar']));

        $admin->setModelManager($modelManager);
        $this->assertSame(['foo', 'bar'], $admin->getExportFields());
    }

    public function testGetPersistentParametersWithNoExtension()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $this->assertEmpty($admin->getPersistentParameters());
    }

    public function testGetPersistentParametersWithInvalidExtension()
    {
        $this->expectException(\RuntimeException::class);

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $extension = $this->createMock(AdminExtensionInterface::class);
        $extension->expects($this->once())->method('getPersistentParameters')->will($this->returnValue(null));

        $admin->addExtension($extension);

        $admin->getPersistentParameters();
    }

    public function testGetPersistentParametersWithValidExtension()
    {
        $expected = [
            'context' => 'foobar',
        ];

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $extension = $this->createMock(AdminExtensionInterface::class);
        $extension->expects($this->once())->method('getPersistentParameters')->will($this->returnValue($expected));

        $admin->addExtension($extension);

        $this->assertSame($expected, $admin->getPersistentParameters());
    }

    public function testGetFormWithNonCollectionParentValue()
    {
        $post = new Post();
        $tagAdmin = $this->createTagAdmin($post);
        $tag = $tagAdmin->getSubject();

        $tag->setPosts(null);
        $tagAdmin->getForm();
        $this->assertSame($post, $tag->getPosts());
    }

    public function testGetFormWithCollectionParentValue()
    {
        $post = new Post();
        $tagAdmin = $this->createTagAdmin($post);
        $tag = $tagAdmin->getSubject();

        // Case of a doctrine collection
        $this->assertInstanceOf(Collection::class, $tag->getPosts());
        $this->assertCount(0, $tag->getPosts());

        $tag->addPost(new Post());

        $this->assertCount(1, $tag->getPosts());

        $tagAdmin->getForm();

        $this->assertInstanceOf(Collection::class, $tag->getPosts());
        $this->assertCount(2, $tag->getPosts());
        $this->assertContains($post, $tag->getPosts());

        // Case of an array
        $tag->setPosts([]);
        $this->assertCount(0, $tag->getPosts());

        $tag->addPost(new Post());

        $this->assertCount(1, $tag->getPosts());

        $tagAdmin->getForm();

        $this->assertInternalType('array', $tag->getPosts());
        $this->assertCount(2, $tag->getPosts());
        $this->assertContains($post, $tag->getPosts());
    }

    public function testRemoveFieldFromFormGroup()
    {
        $formGroups = [
            'foobar' => [
                'fields' => [
                    'foo' => 'foo',
                    'bar' => 'bar',
                ],
            ],
        ];

        $admin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setFormGroups($formGroups);

        $admin->removeFieldFromFormGroup('foo');
        $this->assertSame($admin->getFormGroups(), [
            'foobar' => [
                'fields' => [
                    'bar' => 'bar',
                ],
            ],
        ]);

        $admin->removeFieldFromFormGroup('bar');
        $this->assertSame($admin->getFormGroups(), []);
    }

    public function testGetFilterParameters()
    {
        $authorId = uniqid();

        $postAdmin = new PostAdmin('sonata.post.admin.post', 'Application\Sonata\NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $commentAdmin = new CommentAdmin('sonata.post.admin.comment', 'Application\Sonata\NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentAdmin');
        $commentAdmin->setParentAssociationMapping('post.author');
        $commentAdmin->setParent($postAdmin);

        $request = $this->createMock(Request::class, ['get']);
        $query = $this->createMock(ParameterBag::class, ['get']);
        $query->expects($this->any())
            ->method('get')
            ->will($this->returnValue([]));
        $request->query = $query;
        $request->expects($this->any())
            ->method('get')
            ->will($this->returnValue($authorId));

        $commentAdmin->setRequest($request);

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->expects($this->any())
            ->method('getDefaultSortValues')
            ->will($this->returnValue([]));

        $commentAdmin->setModelManager($modelManager);

        $parameters = $commentAdmin->getFilterParameters();

        $this->assertTrue(isset($parameters['post__author']));
        $this->assertSame(['value' => $authorId], $parameters['post__author']);
    }

    public function testGetFilterFieldDescription()
    {
        $modelAdmin = new ModelAdmin('sonata.post.admin.model', 'Application\Sonata\FooBundle\Entity\Model', 'SonataFooBundle:ModelAdmin');

        $fooFieldDescription = new FieldDescription();
        $barFieldDescription = new FieldDescription();
        $bazFieldDescription = new FieldDescription();

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->expects($this->exactly(3))
            ->method('getNewFieldDescriptionInstance')
            ->will($this->returnCallback(function ($adminClass, $name, $filterOptions) use ($fooFieldDescription, $barFieldDescription, $bazFieldDescription) {
                switch ($name) {
                    case 'foo':
                        $fieldDescription = $fooFieldDescription;

                        break;

                    case 'bar':
                        $fieldDescription = $barFieldDescription;

                        break;

                    case 'baz':
                        $fieldDescription = $bazFieldDescription;

                        break;

                    default:
                        throw new \RuntimeException(sprintf('Unknown filter name "%s"', $name));
                        break;
                }

                $fieldDescription->setName($name);

                return $fieldDescription;
            }));

        $modelAdmin->setModelManager($modelManager);

        $pager = $this->createMock(PagerInterface::class);

        $datagrid = $this->createMock(DatagridInterface::class);
        $datagrid->expects($this->once())
            ->method('getPager')
            ->will($this->returnValue($pager));

        $datagridBuilder = $this->createMock(DatagridBuilderInterface::class);
        $datagridBuilder->expects($this->once())
            ->method('getBaseDatagrid')
            ->with($this->identicalTo($modelAdmin), [])
            ->will($this->returnValue($datagrid));

        $datagridBuilder->expects($this->exactly(3))
            ->method('addFilter')
            ->will($this->returnCallback(function ($datagrid, $type, $fieldDescription, AdminInterface $admin) {
                $admin->addFilterFieldDescription($fieldDescription->getName(), $fieldDescription);
                $fieldDescription->mergeOption('field_options', ['required' => false]);
            }));

        $modelAdmin->setDatagridBuilder($datagridBuilder);

        $this->assertSame(['foo' => $fooFieldDescription, 'bar' => $barFieldDescription, 'baz' => $bazFieldDescription], $modelAdmin->getFilterFieldDescriptions());
        $this->assertFalse($modelAdmin->hasFilterFieldDescription('fooBar'));
        $this->assertTrue($modelAdmin->hasFilterFieldDescription('foo'));
        $this->assertTrue($modelAdmin->hasFilterFieldDescription('bar'));
        $this->assertTrue($modelAdmin->hasFilterFieldDescription('baz'));
        $this->assertSame($fooFieldDescription, $modelAdmin->getFilterFieldDescription('foo'));
        $this->assertSame($barFieldDescription, $modelAdmin->getFilterFieldDescription('bar'));
        $this->assertSame($bazFieldDescription, $modelAdmin->getFilterFieldDescription('baz'));
    }

    public function testGetSubjectNoRequest()
    {
        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager
            ->expects($this->never())
            ->method('find');

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setModelManager($modelManager);

        $this->assertNull($admin->getSubject());
    }

    public function testGetSideMenu()
    {
        $item = $this->createMock(ItemInterface::class);
        $item
            ->expects($this->once())
            ->method('setChildrenAttribute')
            ->with('class', 'nav navbar-nav');
        $item
            ->expects($this->once())
            ->method('setExtra')
            ->with('translation_domain', 'foo_bar_baz');

        $menuFactory = $this->createMock(FactoryInterface::class);
        $menuFactory
            ->expects($this->once())
            ->method('createItem')
            ->will($this->returnValue($item));

        $modelAdmin = new ModelAdmin('sonata.post.admin.model', 'Application\Sonata\FooBundle\Entity\Model', 'SonataFooBundle:ModelAdmin');
        $modelAdmin->setMenuFactory($menuFactory);
        $modelAdmin->setTranslationDomain('foo_bar_baz');

        $modelAdmin->getSideMenu('foo');
    }

    /**
     * @return array
     */
    public function provideGetSubject()
    {
        return [
            [23],
            ['azerty'],
            ['4f69bbb5f14a13347f000092'],
            ['0779ca8d-e2be-11e4-ac58-0242ac11000b'],
            ['123'.AdapterInterface::ID_SEPARATOR.'my_type'], // composite keys are supported
        ];
    }

    /**
     * @dataProvider provideGetSubject
     */
    public function testGetSubjectFailed($id)
    {
        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager
            ->expects($this->once())
            ->method('find')
            ->with('NewsBundle\Entity\Post', $id)
            ->will($this->returnValue(null)); // entity not found

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setModelManager($modelManager);

        $admin->setRequest(new Request(['id' => $id]));
        $this->assertNull($admin->getSubject());
    }

    /**
     * @dataProvider provideGetSubject
     */
    public function testGetSubject($id)
    {
        $entity = new Post();

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager
            ->expects($this->once())
            ->method('find')
            ->with('NewsBundle\Entity\Post', $id)
            ->will($this->returnValue($entity));

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $admin->setModelManager($modelManager);

        $admin->setRequest(new Request(['id' => $id]));
        $this->assertSame($entity, $admin->getSubject());
        $this->assertSame($entity, $admin->getSubject()); // model manager must be used only once
    }

    public function testGetSubjectWithParentDescription()
    {
        $adminId = 1;

        $comment = new Comment();

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager
            ->expects($this->any())
            ->method('find')
            ->with('NewsBundle\Entity\Comment', $adminId)
            ->will($this->returnValue($comment));

        $request = new Request(['id' => $adminId]);

        $postAdmin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $postAdmin->setRequest($request);

        $commentAdmin = new CommentAdmin('sonata.post.admin.comment', 'NewsBundle\Entity\Comment', 'SonataNewsBundle:CommentAdmin');
        $commentAdmin->setRequest($request);
        $commentAdmin->setModelManager($modelManager);

        $this->assertEquals($comment, $commentAdmin->getSubject());

        $commentAdmin->setSubject(null);
        $commentAdmin->setParentFieldDescription(new FieldDescription());

        $this->assertNull($commentAdmin->getSubject());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::configureActionButtons
     */
    public function testGetActionButtonsList()
    {
        $expected = [
            'create' => [
                'template' => 'Foo.html.twig',
            ],
        ];

        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $securityHandler = $this->createMock(SecurityHandlerInterface::class);
        $securityHandler
            ->expects($this->once())
            ->method('isGranted')
            ->with($admin, 'CREATE', $admin)
            ->will($this->returnValue(true));
        $admin->setSecurityHandler($securityHandler);

        $routeGenerator = $this->createMock(RouteGeneratorInterface::class);
        $routeGenerator
            ->expects($this->once())
            ->method('hasAdminRoute')
            ->with($admin, 'create')
            ->will($this->returnValue(true));
        $admin->setRouteGenerator($routeGenerator);

        $admin->setTemplate('button_create', 'Foo.html.twig');

        $this->assertSame($expected, $admin->getActionButtons('list', null));
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::configureActionButtons
     */
    public function testGetActionButtonsListCreateDisabled()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');

        $securityHandler = $this->createMock(SecurityHandlerInterface::class);
        $securityHandler
            ->expects($this->once())
            ->method('isGranted')
            ->with($admin, 'CREATE', $admin)
            ->will($this->returnValue(false));
        $admin->setSecurityHandler($securityHandler);

        $this->assertSame([], $admin->getActionButtons('list', null));
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::configureBatchActions
     */
    public function testGetBatchActions()
    {
        $expected = [
            'delete' => [
                'label' => 'action_delete',
                'translation_domain' => 'SonataAdminBundle',
                'ask_confirmation' => true, // by default always true
            ],
            'foo' => [
                'label' => 'action_foo',
                'translation_domain' => 'SonataAdminBundle',
            ],
            'bar' => [
                'label' => 'batch.label_bar',
                'translation_domain' => 'SonataAdminBundle',
            ],
            'baz' => [
                'label' => 'action_baz',
                'translation_domain' => 'AcmeAdminBundle',
            ],
        ];

        $pathInfo = new PathInfoBuilder($this->createMock(AuditManagerInterface::class));

        $labelTranslatorStrategy = $this->createMock(LabelTranslatorStrategyInterface::class);
        $labelTranslatorStrategy->expects($this->any())
            ->method('getLabel')
            ->will($this->returnCallback(function ($label, $context = '', $type = '') {
                return $context.'.'.$type.'_'.$label;
            }));

        $admin = new PostAdmin('sonata.post.admin.model', 'Application\Sonata\FooBundle\Entity\Model', 'SonataFooBundle:ModelAdmin');
        $admin->setRouteBuilder($pathInfo);
        $admin->setTranslationDomain('SonataAdminBundle');
        $admin->setLabelTranslatorStrategy($labelTranslatorStrategy);

        $routeGenerator = $this->createMock(RouteGeneratorInterface::class);
        $routeGenerator
            ->expects($this->once())
            ->method('hasAdminRoute')
            ->with($admin, 'delete')
            ->will($this->returnValue(true));
        $admin->setRouteGenerator($routeGenerator);

        $securityHandler = $this->createMock(SecurityHandlerInterface::class);
        $securityHandler->expects($this->any())
            ->method('isGranted')
            ->will($this->returnCallback(function (AdminInterface $adminIn, $attributes, $object = null) use ($admin) {
                if ($admin == $adminIn && 'DELETE' == $attributes) {
                    return true;
                }

                return false;
            }));
        $admin->setSecurityHandler($securityHandler);

        $this->assertSame($expected, $admin->getBatchActions());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::showMosaicButton
     */
    public function testShowMosaicButton()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $listModes = $admin->getListModes();

        $admin->showMosaicButton(true);

        $this->assertSame($listModes, $admin->getListModes());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::showMosaicButton
     */
    public function testShowMosaicButtonHideMosaic()
    {
        $admin = new PostAdmin('sonata.post.admin.post', 'NewsBundle\Entity\Post', 'SonataNewsBundle:PostAdmin');
        $listModes = $admin->getListModes();
        $expected['list'] = $listModes['list'];

        $admin->showMosaicButton(false);

        $this->assertSame($expected, $admin->getListModes());
    }

    /**
     * @covers \Sonata\AdminBundle\Admin\AbstractAdmin::getDashboardActions
     * @dataProvider provideGetBaseRouteName
     */
    public function testDefaultDashboardActionsArePresent($objFqn, $expected)
    {
        $pathInfo = new PathInfoBuilder($this->createMock(AuditManagerInterface::class));

        $routeGenerator = new DefaultRouteGenerator(
            $this->createMock(RouterInterface::class),
            new RoutesCache($this->cacheTempFolder, true)
        );

        $admin = new PostAdmin('sonata.post.admin.post', $objFqn, 'SonataNewsBundle:PostAdmin');
        $admin->setRouteBuilder($pathInfo);
        $admin->setRouteGenerator($routeGenerator);
        $admin->initialize();

        $securityHandler = $this->createMock(SecurityHandlerInterface::class);
        $securityHandler->expects($this->any())
            ->method('isGranted')
            ->will($this->returnCallback(function (AdminInterface $adminIn, $attributes, $object = null) use ($admin) {
                if ($admin == $adminIn && ('CREATE' == $attributes || 'LIST' == $attributes)) {
                    return true;
                }

                return false;
            }));

        $admin->setSecurityHandler($securityHandler);

        $this->assertArrayHasKey('list', $admin->getDashboardActions());
        $this->assertArrayHasKey('create', $admin->getDashboardActions());
    }

    public function testDefaultFilters()
    {
        $admin = new FilteredAdmin('sonata.post.admin.model', 'Application\Sonata\FooBundle\Entity\Model', 'SonataFooBundle:ModelAdmin');

        $subjectId = uniqid();

        $request = $this->createMock(Request::class, ['get']);
        $query = $this->createMock(ParameterBag::class, ['set', 'get']);
        $query->expects($this->any())
            ->method('get')
            ->with($this->equalTo('filter'))
            ->will($this->returnValue([
                'a' => [
                    'value' => 'b',
                ],
                'foo' => [
                    'type' => '1',
                    'value' => 'bar',
                ],
                'baz' => [
                    'type' => '5',
                    'value' => 'test',
                ],
            ]));
        $request->query = $query;

        $request->expects($this->any())
            ->method('get')
            ->will($this->returnValue($subjectId));

        $admin->setRequest($request);

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->expects($this->any())
            ->method('getDefaultSortValues')
            ->will($this->returnValue([]));

        $admin->setModelManager($modelManager);

        $this->assertEquals([
            'foo' => [
                'type' => '1',
                'value' => 'bar',
            ],
            'baz' => [
                'type' => '5',
                'value' => 'test',
            ],
            '_page' => 1,
            '_per_page' => 32,
            'a' => [
                'value' => 'b',
            ],
        ], $admin->getFilterParameters());

        $this->assertTrue($admin->isDefaultFilter('foo'));
        $this->assertFalse($admin->isDefaultFilter('bar'));
        $this->assertFalse($admin->isDefaultFilter('a'));
    }

    /**
     * @group legacy
     */
    public function testDefaultBreadcrumbsBuilder()
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('getParameter')
            ->with('sonata.admin.configuration.breadcrumbs')
            ->will($this->returnValue([]));

        $pool = $this->getMockBuilder(Pool::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pool->expects($this->once())
            ->method('getContainer')
            ->will($this->returnValue($container));

        $admin = $this->getMockForAbstractClass(AbstractAdmin::class, [
            'admin.my_code', 'My\Class', 'MyBundle:ClassAdmin',
        ], '', true, true, true, ['getConfigurationPool']);
        $admin->expects($this->once())
            ->method('getConfigurationPool')
            ->will($this->returnValue($pool));

        $this->assertInstanceOf(BreadcrumbsBuilder::class, $admin->getBreadcrumbsBuilder());
    }

    /**
     * @group legacy
     */
    public function testBreadcrumbsBuilderSetter()
    {
        $admin = $this->getMockForAbstractClass(AbstractAdmin::class, [
            'admin.my_code', 'My\Class', 'MyBundle:ClassAdmin',
        ]);
        $this->assertSame($admin, $admin->setBreadcrumbsBuilder($builder = $this->createMock(
            BreadcrumbsBuilderInterface::class
        )));
        $this->assertSame($builder, $admin->getBreadcrumbsBuilder());
    }

    /**
     * @group legacy
     */
    public function testGetBreadcrumbs()
    {
        $admin = $this->getMockForAbstractClass(AbstractAdmin::class, [
            'admin.my_code', 'My\Class', 'MyBundle:ClassAdmin',
        ]);
        $builder = $this->prophesize(BreadcrumbsBuilderInterface::class);
        $action = 'myaction';
        $builder->getBreadcrumbs($admin, $action)->shouldBeCalled();
        $admin->setBreadcrumbsBuilder($builder->reveal())->getBreadcrumbs($action);
    }

    /**
     * @group legacy
     */
    public function testBuildBreadcrumbs()
    {
        $admin = $this->getMockForAbstractClass(AbstractAdmin::class, [
            'admin.my_code', 'My\Class', 'MyBundle:ClassAdmin',
        ]);
        $builder = $this->prophesize(BreadcrumbsBuilderInterface::class);
        $action = 'myaction';
        $menu = $this->createMock(ItemInterface::class);
        $builder->buildBreadcrumbs($admin, $action, $menu)
            ->shouldBeCalledTimes(1)
            ->willReturn($menu);
        $admin->setBreadcrumbsBuilder($builder->reveal());

        /* check the called is proxied only once */
        $this->assertSame($menu, $admin->buildBreadcrumbs($action, $menu));
        $this->assertSame($menu, $admin->buildBreadcrumbs($action, $menu));
    }

    /**
     * NEXT_MAJOR: remove this method.
     *
     * @group legacy
     */
    public function testCreateQueryLegacyCallWorks()
    {
        $admin = $this->getMockForAbstractClass(AbstractAdmin::class, [
            'admin.my_code', 'My\Class', 'MyBundle:ClassAdmin',
        ]);
        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->expects($this->once())
            ->method('createQuery')
            ->with('My\Class')
            ->willReturn('a query');

        $admin->setModelManager($modelManager);
        $this->assertSame('a query', $admin->createQuery('list'));
    }

    public function testGetDataSourceIterator()
    {
        $datagrid = $this->createMock(DatagridInterface::class);
        $datagrid->method('buildPager');

        $modelManager = $this->createMock(ModelManagerInterface::class);
        $modelManager->method('getExportFields')->will($this->returnValue([
            'field',
            'foo',
            'bar',
        ]));
        $modelManager->expects($this->once())->method('getDataSourceIterator')
            ->with($this->equalTo($datagrid), $this->equalTo([
                'Feld' => 'field',
                1 => 'foo',
                2 => 'bar',
            ]));

        $admin = $this->getMockBuilder(AbstractAdmin::class)
            ->disableOriginalConstructor()
            ->setMethods(['getDatagrid', 'getTranslationLabel', 'trans'])
            ->getMockForAbstractClass();
        $admin->method('getDatagrid')->will($this->returnValue($datagrid));
        $admin->setModelManager($modelManager);

        $admin->expects($this->any())
            ->method('getTranslationLabel')
            ->will($this->returnCallback(function ($label, $context = '', $type = '') {
                return $context.'.'.$type.'_'.$label;
            }));
        $admin->expects($this->any())
            ->method('trans')
            ->will($this->returnCallback(function ($label) {
                if ('export.label_field' == $label) {
                    return 'Feld';
                }

                return $label;
            }));

        $admin->getDataSourceIterator();
    }

    public function testCircularChildAdmin()
    {
        $this->expectException(
            \RuntimeException::class,
            'Circular reference detected! The child admin `sonata.post.admin.post` is already in the parent tree of the `sonata.post.admin.comment` admin.'
        );

        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $postAdmin->addChild($commentAdmin);
        $commentAdmin->addChild($postAdmin);
    }

    public function testCircularChildAdminTripleLevel()
    {
        $this->expectException(
            \RuntimeException::class,
            'Circular reference detected! The child admin `sonata.post.admin.post` is already in the parent tree of the `sonata.post.admin.comment_vote` admin.'
        );

        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );
        $postAdmin->addChild($commentAdmin);
        $commentAdmin->addChild($commentVoteAdmin);
        $commentVoteAdmin->addChild($postAdmin);
    }

    public function testCircularChildAdminWithItself()
    {
        $this->expectException(
            \RuntimeException::class,
            'Circular reference detected! The child admin `sonata.post.admin.post` is already in the parent tree of the `sonata.post.admin.post` admin.'
        );

        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $postAdmin->addChild($postAdmin);
    }

    public function testGetRootAncestor()
    {
        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );

        $this->assertSame($postAdmin, $postAdmin->getRootAncestor());
        $this->assertSame($commentAdmin, $commentAdmin->getRootAncestor());
        $this->assertSame($commentVoteAdmin, $commentVoteAdmin->getRootAncestor());

        $postAdmin->addChild($commentAdmin);

        $this->assertSame($postAdmin, $postAdmin->getRootAncestor());
        $this->assertSame($postAdmin, $commentAdmin->getRootAncestor());
        $this->assertSame($commentVoteAdmin, $commentVoteAdmin->getRootAncestor());

        $commentAdmin->addChild($commentVoteAdmin);

        $this->assertSame($postAdmin, $postAdmin->getRootAncestor());
        $this->assertSame($postAdmin, $commentAdmin->getRootAncestor());
        $this->assertSame($postAdmin, $commentVoteAdmin->getRootAncestor());
    }

    public function testGetChildDepth()
    {
        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );

        $this->assertSame(0, $postAdmin->getChildDepth());
        $this->assertSame(0, $commentAdmin->getChildDepth());
        $this->assertSame(0, $commentVoteAdmin->getChildDepth());

        $postAdmin->addChild($commentAdmin);

        $this->assertSame(0, $postAdmin->getChildDepth());
        $this->assertSame(1, $commentAdmin->getChildDepth());
        $this->assertSame(0, $commentVoteAdmin->getChildDepth());

        $commentAdmin->addChild($commentVoteAdmin);

        $this->assertSame(0, $postAdmin->getChildDepth());
        $this->assertSame(1, $commentAdmin->getChildDepth());
        $this->assertSame(2, $commentVoteAdmin->getChildDepth());
    }

    public function testGetCurrentLeafChildAdmin()
    {
        $postAdmin = new PostAdmin(
            'sonata.post.admin.post',
            'Application\Sonata\NewsBundle\Entity\Post',
            'SonataNewsBundle:PostAdmin'
        );
        $commentAdmin = new CommentAdmin(
            'sonata.post.admin.comment',
            'Application\Sonata\NewsBundle\Entity\Comment',
            'SonataNewsBundle:CommentAdmin'
        );
        $commentVoteAdmin = new CommentVoteAdmin(
            'sonata.post.admin.comment_vote',
            'Application\Sonata\NewsBundle\Entity\CommentVote',
            'SonataNewsBundle:CommentVoteAdmin'
        );

        $postAdmin->addChild($commentAdmin);
        $commentAdmin->addChild($commentVoteAdmin);

        $this->assertNull($postAdmin->getCurrentLeafChildAdmin());
        $this->assertNull($commentAdmin->getCurrentLeafChildAdmin());
        $this->assertNull($commentVoteAdmin->getCurrentLeafChildAdmin());

        $commentAdmin->setCurrentChild(true);

        $this->assertSame($commentAdmin, $postAdmin->getCurrentLeafChildAdmin());
        $this->assertNull($commentAdmin->getCurrentLeafChildAdmin());
        $this->assertNull($commentVoteAdmin->getCurrentLeafChildAdmin());

        $commentVoteAdmin->setCurrentChild(true);

        $this->assertSame($commentVoteAdmin, $postAdmin->getCurrentLeafChildAdmin());
        $this->assertSame($commentVoteAdmin, $commentAdmin->getCurrentLeafChildAdmin());
        $this->assertNull($commentVoteAdmin->getCurrentLeafChildAdmin());
    }

    private function createTagAdmin(Post $post)
    {
        $postAdmin = $this->getMockBuilder(PostAdmin::class)
            ->disableOriginalConstructor()
            ->getMock();

        $postAdmin->expects($this->any())->method('getObject')->will($this->returnValue($post));

        $formBuilder = $this->createMock(FormBuilderInterface::class);
        $formBuilder->expects($this->any())->method('getForm')->will($this->returnValue(null));

        $tagAdmin = $this->getMockBuilder(TagAdmin::class)
            ->setConstructorArgs([
                'admin.tag',
                Tag::class,
                'MyBundle:MyController',
            ])
            ->setMethods(['getFormBuilder'])
            ->getMock();

        $tagAdmin->expects($this->any())->method('getFormBuilder')->will($this->returnValue($formBuilder));
        $tagAdmin->setParent($postAdmin);

        $tag = new Tag();
        $tagAdmin->setSubject($tag);

        $request = $this->createMock(Request::class);
        $tagAdmin->setRequest($request);

        $configurationPool = $this->getMockBuilder(Pool::class)
            ->disableOriginalConstructor()
            ->getMock();

        $configurationPool->expects($this->any())->method('getPropertyAccessor')->will($this->returnValue(PropertyAccess::createPropertyAccessor()));

        $tagAdmin->setConfigurationPool($configurationPool);

        return $tagAdmin;
    }
}