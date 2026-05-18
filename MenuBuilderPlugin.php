<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder;

use App\Application\Devflow;
use App\Infrastructure\Services\Plugin;
use App\Shared\Services\Registry;
use App\Shared\Services\Utils;
use Plugin\MenuBuilder\Controller\NavigationController;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Qubus\EventDispatcher\ActionFilter\Action;
use Qubus\Exception\Data\TypeException;
use Qubus\Exception\Exception;
use Qubus\Expressive\Schema\CreateTable;
use Qubus\Http\ServerRequest;
use Qubus\Routing\Psr7Router;
use ReflectionException;
use Throwable;

use function App\Shared\Helpers\add_plugins_submenu;
use function App\Shared\Helpers\cms_enqueue_css;
use function App\Shared\Helpers\cms_enqueue_js;
use function App\Shared\Helpers\plugin_basename;
use function App\Shared\Helpers\plugin_dir_path;
use function App\Shared\Helpers\plugin_url;
use function dirname;
use function get_class;
use function Qubus\Security\Helpers\esc_html__;

final class MenuBuilderPlugin extends Plugin
{
    /**
     * @throws ReflectionException
     * @throws Exception
     */
    public function meta(): array
    {
        $plugin = [
            'name' => esc_html__(string: 'Menu Builder', domain: 'menu-builder'),
            'id' => 'menu-builder',
            'slug' => 'MenuBuilder',
            'author' => 'Joshua Parker',
            'version' => '1.0.0',
            'description' => 'Full featured navigation/menu builder plugin for Devflow CMF.',
            'basename' => plugin_basename(dirname(__FILE__)),
            'path' => plugin_dir_path(dirname(__FILE__)),
            'url' => plugin_url('', __CLASS__),
            'pluginUri' => 'https://github.com/getdevflow/menu-builder',
            'authorUri' => 'https://joshuaparker.dev/',
            'className' => get_class($this),
            'screenshot' => plugin_url('MenuBuilder/images/screenshot.png'),
        ];

        Registry::getInstance()->set('menu-builder', $plugin);

        return $plugin;
    }

    /**
     * @throws ReflectionException
     */
    public function handle(): void
    {
        Action::getInstance()->addAction('cms_admin_head', [$this, 'enqueueStyles']);
        Action::getInstance()->addAction('cms_admin_footer', [$this, 'enqueueScripts']);
        Action::getInstance()->addAction('plugins_submenu', [$this, 'registerSubmenu']);
        Action::getInstance()->addAction('plugins_loaded', [$this, 'render'], 1);
    }

    /**
     * @throws Exception
     */
    protected function migrateUp(): void
    {
        if (!$this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'navigation_menu')) {
            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'navigation_menu',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'menu_id', length: 36)
                            ->primary()
                            ->unique(name: $this->dfdb->prefix . 'menu_id');
                        $table->string(name: 'menu_name', length: 191)->notNull();
                        $table->string(name: 'menu_slug', length: 191)->notNull()->unique();
                        $table->text(name: 'menu_description')->size(value: 'big');
                    }
                );
        };

        if (!$this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'navigation_item')) {
            $this->dfdb->schema()
                ->create(
                    table: $this->dfdb->prefix . 'navigation_item',
                    callback: function (CreateTable $table) {
                        $table->string(name: 'item_id', length: 36)
                            ->primary()
                            ->unique(name: $this->dfdb->prefix . 'item_id');
                        $table->string(name: 'menu_id', length: 36)->notNull();
                        $table->string(name: 'parent_id', length: 36);
                        $table->string(name: 'item_type', length: 50)->notNull();
                        $table->string(name: 'object_id', length: 191);
                        $table->string(name: 'object_type', length: 191);
                        $table->string(name: 'item_custom_label', length: 191);
                        $table->string(name: 'item_custom_url', length: 191);
                        $table->string(name: 'item_target', length: 20)->notNull()->defaultValue('_self');
                        $table->string(name: 'item_css_class', length: 191);
                        $table->integer(name: 'item_position')->notNull()->defaultValue(0);
                        $table->text(name: 'item_attribute')->size(value: 'big');
                        $table->index(['menu_id','parent_id','item_position'], $this->dfdb->prefix . 'item_sort');

                        $table->foreign('menu_id', $this->dfdb->prefix . 'item_menu')
                            ->references($this->dfdb->prefix . 'navigation_menu', 'menu_id')
                            ->onDelete('cascade')
                            ->onUpdate('cascade');

                        $table->foreign('parent_id', $this->dfdb->prefix . 'item_parent')
                            ->references($this->dfdb->prefix . 'navigation_item', 'item_id')
                            ->onDelete('cascade')
                            ->onUpdate('cascade');
                    }
                );
        };
    }

    /**
     * @throws Exception
     */
    protected function migrateDown(): void
    {
        if ($this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'navigation_item')) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'navigation_item');
        }

        if ($this->dfdb->schema()->hasTable(table: $this->dfdb->prefix . 'navigation_menu')) {
            $this->dfdb->schema()->drop(table: $this->dfdb->prefix . 'navigation_menu');
        }
    }

    /**
     * @throws Exception
     */
    public function enqueueStyles(): void
    {
        if (
                !str_starts_with(
                    Utils::getPathInfo(
                        '/admin/plugin/' . $this->id() . '/'
                    ),
                    '/admin/plugin/' . $this->id() . '/'
                )
        ) {
            return;
        }

        cms_enqueue_css(
            config: 'plugin',
            asset: $this->url() . '/css/navigation.css',
            slug: $this->id()
        );
    }

    /**
     * @throws Exception
     */
    public function enqueueScripts(): void
    {
        if (
            !str_starts_with(
                Utils::getPathInfo(
                    '/admin/plugin/' . $this->id() . '/'
                ),
                '/admin/plugin/' . $this->id() . '/'
            )
        ) {
            return;
        }

        cms_enqueue_js(
            config: 'plugin',
            asset: $this->url() . '/js/navigation.js',
            slug: $this->id()
        );
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws ReflectionException
     * @throws TypeException
     */
    public function registerSubmenu(): void
    {
        echo add_plugins_submenu(
            menuTitle: $this->meta()['name'],
            menuRoute: 'plugin/' . $this->meta()['id'],
            screen: $this->meta()['id'],
            permission: 'manage:plugins'
        );
    }

    /**
     * @throws ReflectionException
     * @throws \Exception
     * @throws Throwable
     */
    public function render(): void
    {
        $router = Devflow::$PHP->make(Psr7Router::class);

        $router->group('/admin/plugin/menu-builder', function ($route) {
            $route->get(
                '/',
                fn(ServerRequest $request, NavigationController $controller) => $controller->index($request)
            );
            $route->post(
                '/create-menu',
                fn(ServerRequest $request, NavigationController $controller) => $controller->createMenu($request)
            );
            $route->post(
                '/update-menu',
                fn(ServerRequest $request, NavigationController $controller) => $controller->updateMenu($request)
            );
            $route->post(
                '/delete-menu',
                fn(ServerRequest $request, NavigationController $controller) => $controller->deleteMenu($request)
            );
            $route->post(
                '/add-menu-item',
                fn(ServerRequest $request, NavigationController $controller) => $controller->addItem($request)
            );
            $route->post(
                '/update-menu-item',
                fn(ServerRequest $request, NavigationController $controller) => $controller->updateItem($request)
            );
            $route->post(
                '/remove-menu-item',
                fn(ServerRequest $request, NavigationController $controller) => $controller->removeItem($request)
            );
            $route->post(
                '/reorder',
                fn(ServerRequest $request, NavigationController $controller) => $controller->reorder($request)
            );
        });
    }

    /**
     * @throws Exception
     */
    public function onActivation(): void
    {
        $this->migrateUp();
    }

    public function onDeactivation(): void
    {
        $this->migrateDown();
    }
}
