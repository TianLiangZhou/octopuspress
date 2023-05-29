<?php
namespace App;

use OctopusPress\Bundle\Bridge\Bridger;
use OctopusPress\Bundle\Entity\TermTaxonomy;
use OctopusPress\Bundle\OctopusPressKernel;
use OctopusPress\Bundle\Plugin\PluginInterface;
use OctopusPress\Bundle\Plugin\PluginProviderInterface;

class Kernel extends OctopusPressKernel implements PluginInterface
{

    public function launcher(Bridger $bridger): void
    {
        // TODO: Implement launcher() method.
        $post = $bridger->getPost();
        $post->registerType(
            'plugin',
            [
                'label' => '插件',
                'taxonomies' => [TermTaxonomy::TAG, TermTaxonomy::CATEGORY],
                'supports' => [
                    'author', 'title', 'editor', 'excerpt', 'thumbnail'
                ],
            ]
        );
        $post->registerType(
            'theme',
            [
                'label' => '主题',
                'taxonomies' => [TermTaxonomy::TAG, TermTaxonomy::CATEGORY],
                'supports' => [
                    'author', 'title', 'editor', 'excerpt', 'thumbnail'
                ],
            ]
        );
        $meta = $bridger->getMeta();
        foreach (['version', 'entrypoint', 'logo', 'screenshot', 'keywords', 'authors', 'homepage', 'miniOP', 'miniPHP'] as $name) {
            $meta->registerPost(['theme', 'plugin'], $name, []);
        }
        $bridger->getPlugin()->addTypeMenu('plugin', '插件', ['parent' => 'backend_post'])
            ->addTypeMenu('theme', '主题', ['parent' => 'backend_post']);
    }

    public function activate(Bridger $bridger): void
    {
        // TODO: Implement activate() method.
    }

    public function uninstall(Bridger $bridger): void
    {
        // TODO: Implement uninstall() method.
    }

    public function getServices(Bridger $bridger): array
    {
        // TODO: Implement getServices() method.
        return [];
    }

    public function provider(Bridger $bridger): ?PluginProviderInterface
    {
        // TODO: Implement provider() method.
        return null;
    }
}
