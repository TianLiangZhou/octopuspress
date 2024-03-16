<?php

namespace App\Controller;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use OctopusPress\Bundle\Bridge\Bridger;
use OctopusPress\Bundle\Controller\Admin\OpenController;
use OctopusPress\Bundle\Controller\Admin\PostController;
use OctopusPress\Bundle\Controller\Admin\TaxonomyController;
use OctopusPress\Bundle\Controller\Controller;
use OctopusPress\Bundle\Entity\Post;
use OctopusPress\Bundle\Entity\PostMeta;
use OctopusPress\Bundle\Entity\TermTaxonomy;
use OctopusPress\Bundle\Entity\User;
use OctopusPress\Bundle\Model\PluginManager;
use OctopusPress\Bundle\Twig\OctopusRuntime;
use OctopusPress\Bundle\Util\Formatter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Error\RuntimeError;

/**
 *
 */
class PackageController extends Controller
{

    private PluginManager $pluginManager;

    public function __construct(Bridger $bridger, PluginManager $pluginManager)
    {
        parent::__construct($bridger);
        $this->pluginManager = $pluginManager;
    }

    /**
     * @throws RuntimeError
     */
    #[Route('/package/{type}.{_format}', requirements: ['type' => '(theme|plugin)', '_format' => '(json)'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function packages(string $type, Request $request): Response
    {
        try {
            $names = $request->query->all('name');
        } catch (\Throwable $exception) {
            $names = [];
        }
        /**
         * @var $runtime OctopusRuntime
         */
        $runtime = $this->bridger->getTwig()->getRuntime(OctopusRuntime::class);
        $condition = [
            'type' => $type,
            'status' => Post::STATUS_PUBLISHED,
        ];
        foreach ($names as $name) {
            if (is_string($name)) {
                $condition['name'][] = $name;
            }
        }
        $packages = $runtime->getPosts($condition);
        $assets = $this->bridger->getPackages();
        $registeredMetaKeys = $this->bridger->getMeta()->getPostType($type);
        $keys = [];
        foreach ($registeredMetaKeys as $metaKey) {
            $keys[] = $metaKey['key'];
        }
        if ('json' === $request->getRequestFormat()) {
            $secondaryPackages = [];
            foreach ($packages as $item) {
                /**
                 * @var $item Post
                 */
                $metas = [];
                foreach ($item->getMetas() as $meta) {
                    $key = $meta->getMetaKey();
                    if (!in_array($key, $keys)) {
                        continue;
                    }
                    $value= $meta->getMetaValue(true);
                    if ($value && in_array($key, ['logo', 'screenshot'])) {
                        $value = $assets->getUrl($value);
                    }
                    $metas[$key] = $value;
                }
                $secondaryPackages[] = [
                    'packageName'  => $item->getName(),
                    'name'         => $item->getTitle(),
                    'description'  => $item->getExcerpt(),
                    'keywords'     => [],
                    'version'      => $metas['version'],
                    'authors'      => $metas['authors'],
                    'homepage'     => $metas['homepage'],
                    'entrypoint'   => $metas['entrypoint'],
                    'miniOP'       => $metas['miniOP'],
                    'miniPHP'      => $metas['miniPHP'],
                    'logo'         => $metas['logo'],
                    'screenshot'   => $metas['screenshot'],
                ];
            }

            return $this->json([
                'total'    => $runtime->widget('pagination')->get()['total'] ?? count($secondaryPackages),
                'packages' => $secondaryPackages,
            ]);
        }
        return $this->render('package.html.twig', [
            'packages' => $packages,
        ]);
    }


    #[Route('/package/{name}.{_format}', requirements: ['name' => '[a-z0-9_.-]+?', '_format' => '(json)'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function view(Request $request, string $name): Response
    {
        $postRepository = $this->bridger->getPostRepository();
        $package = $postRepository->findOneBy([
            'name' => $name,
        ]);
        if ($package == null) {
            throw $this->createNotFoundException('`'.$name.'` not found for name');
        }
        $registeredMetaKeys = $this->bridger->getMeta()->getPostType($package->getType());
        $keys = [];
        foreach ($registeredMetaKeys as $metaKey) {
            $keys[] = $metaKey['key'];
        }
        if ($request->getRequestFormat() === 'json') {
            $metas = [];
            foreach ($package->getMetas() as $meta) {
                $key = $meta->getMetaKey();
                if (!in_array($key, $keys)) {
                    continue;
                }
                $value= $meta->getMetaValue(true);
                if ($value && in_array($key, ['logo', 'screenshot'])) {
                    $value = $this->bridger->getPackages()->getUrl($value);
                }
                $metas[$key] = $value;
            }
            $info = [
                'packageName'  => $package->getName(),
                'name'         => $package->getTitle(),
                'description'  => $package->getExcerpt(),
                'keywords'     => [],
                'version'      => $metas['version'],
                'authors'      => $metas['authors'],
                'homepage'     => $metas['homepage'],
                'entrypoint'   => $metas['entrypoint'],
                'miniOP'       => $metas['miniOP'],
                'miniPHP'      => $metas['miniPHP'],
                'logo'         => $metas['logo'],
                'screenshot'   => $metas['screenshot'],
            ];

            return $this->json($info);
        }
        return $this->render('');
    }


    #[Route('/package/{name}/download', requirements: ['name' => '[a-z0-9_.-]+?'])]
    public function download(Request $request, string $name): Response
    {
        $postRepository = $this->bridger->getPostRepository();
        $package = $postRepository->findOneBy([
            'name' => $name,
        ]);
        if ($package == null) {
            throw $this->createNotFoundException('`'.$name.'` not found for name');
        }
        $assetDir = $this->bridger->getBuildAssetsDir() ?: $this->bridger->getPublicDir();
        $o = substr(md5($name . $_SERVER['APP_SECRET']), 0, 8);
        $version = $package->getMetaValue('version');
        $packageFile = $assetDir . DIRECTORY_SEPARATOR . 'upload/files/'.$package->getType().'/' . $o . '/'. $name
            .'/v'.$version . '.zip';
        if (!file_exists($packageFile)) {
            throw $this->createNotFoundException('`'.$name.'` not found for name');
        }
        $download = $package->getMeta('download');
        if ($download == null) {
            $download = new PostMeta();
            $download->setPost($package);
            $download->setMetaKey('download');
            $download->setMetaValue(0);
        }
        $count = (int) $download->getMetaValue(true);
        $download->setMetaValue($count + 1);
        $this->getEM()->persist($download);
        $this->getEM()->flush();
        return $this->file($packageFile, $name . '_' . $version . '.zip');
    }

    /**
     * @param User $user
     * @return JsonResponse
     * @throws \Exception
     */
    #[Route('/package/upload', methods: Request::METHOD_POST)]
    public function upload(#[CurrentUser] User $user): JsonResponse
    {
        $this->isGranted('ROLE_USER');
        /**
         * @var $response JsonResponse
         */
        $response = $this->forward(
            OpenController::class . '::upload'
        );
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }
        $body = json_decode($response->getContent(), true);
        $zipFile = $this->bridger->getTempDir() . '/' . $body['filename'];
        if (pathinfo($zipFile, PATHINFO_EXTENSION) !== 'zip') {
            unlink($zipFile);
            return $this->json(['message' => 'Not package file']);
        }
        $packageInfo = $this->pluginManager->getPackageInfo($zipFile);
        $packageInfo['type'] = isset($packageInfo['composerFile']) ? '插件' : '主题';
        unset($packageInfo['composerFile'], $packageInfo['tempDir'], $packageInfo['packageFile']);
        $packageInfo['filename'] = $body['filename'];
        return $this->json($packageInfo);
    }

    /**
     * @param User $user
     * @param Request $request
     * @return Response
     */
    #[Route('/package/submit', methods: Request::METHOD_POST)]
    public function submit(#[CurrentUser] User $user, Request $request): Response
    {
        $this->isGranted('ROLE_USER');
        if (!$request->request->has('filename') || empty($file = $request->request->get('filename'))) {
            return $this->redirectToRoute('user_packages', ['name' => $user->getAccount()]);
        }
        $zipFile = $this->bridger->getTempDir() . '/' . $file;
        if (pathinfo($zipFile, PATHINFO_EXTENSION) !== 'zip') {
            unlink($zipFile);
            $this->addFlash('error', '不是有效的zip文件.');
            return $this->redirectToRoute('user_packages', ['name' => $user->getAccount()]);
        }
        try {
            $packageInfo = $this->pluginManager->getPackageInfo($zipFile);
            $package = $this->save($request, $user, $packageInfo, $zipFile);
            $this->addFlash('success', '提交成功！' . $package->getTitle());
        } catch (\Throwable $exception) {
            unlink($zipFile);
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('user_packages', ['name' => $user->getAccount()]);
        }
        return $this->redirectToRoute('user_packages', ['name' => $user->getAccount()]);
    }

    /**
     * @param User $user
     * @return Response
     */
    #[Route('/user/{name}/packages', name: 'user_packages')]
    public function selfPackages(#[CurrentUser] User $user): Response
    {
        $this->isGranted('ROLE_USER');
        $postRepository = $this->bridger->getPostRepository();
        $packages = $postRepository->createQuery([
            'author' => $user->getId(),
            'status' => [
                Post::STATUS_DRAFT,
                Post::STATUS_PUBLISHED,
            ],
            'type'   => ['theme', 'plugin']
        ])->getResult();
        return $this->render('self-package.html.twig', [
            'packages' => $packages,
        ]);
    }

    /**
     * @param User $user
     * @param array $packageInfo
     * @param string $zipFile
     * @return Post
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws CommonMarkException
     * @throws \Throwable
     */
    private function save(Request $request, User $user, array $packageInfo, string $zipFile): Post
    {
        $postRepository = $this->bridger->getPostRepository();
        $taxonomyRepository = $this->bridger->getTaxonomyRepository();
        $name = str_replace('/', '_', $packageInfo['packageName']);
        $type = !empty($packageInfo['composerFile']) ? 'plugin' : 'theme';
        $package = $postRepository->findOneBy(['name' => $name]);
        if ($package == null) {
            $package = new Post();
        }
        if (($author = $package->getAuthor()) && $author->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('已存在相同类型的包名,请更换包名再提交.');
        }
        $version = $package->getMetaValue('version');
        if ($version) {
            if (version_compare($packageInfo['version'], $version, '<=')) {
                throw new \InvalidArgumentException('提交的版本小于已存在的版本.');
            }
        }
        $filesystem = new Filesystem();
        $baseDir = isset($packageInfo['composerFile']) ? dirname($packageInfo['composerFile']) : dirname($packageInfo['packageFile']);
        $assetDir = $this->bridger->getBuildAssetsDir() ?: $this->bridger->getPublicDir();
        if ($packageInfo['screenshot']) {
            $screenshot = $baseDir . DIRECTORY_SEPARATOR . ltrim(ltrim($packageInfo['screenshot'], './'), './');
            $path = $assetDir . DIRECTORY_SEPARATOR . 'upload/images/screenshot/' . $name;
            if (!file_exists($path)) {
                $filesystem->mkdir($path, 0755);
            }
            if (file_exists($screenshot) && getimagesize($screenshot)) {
                $filesystem->copy($screenshot, $path . '/' . pathinfo($screenshot, PATHINFO_FILENAME));
                $packageInfo['screenshot'] = 'upload/images/screenshot/' . $name . '/' . pathinfo($screenshot, PATHINFO_FILENAME);
            } else {
                $packageInfo['screenshot'] = '';
            }
        }
        if ($packageInfo['logo']) {
            $screenshot = $baseDir . DIRECTORY_SEPARATOR . ltrim(ltrim($packageInfo['logo'], './'), './');
            $path = $assetDir . DIRECTORY_SEPARATOR . 'upload/images/logo/' . $name;
            if (!file_exists($path)) {
                $filesystem->mkdir($path, 0755);
            }
            if (file_exists($screenshot) && getimagesize($screenshot)) {
                $filesystem->copy($screenshot, $path . '/' . pathinfo($screenshot, PATHINFO_FILENAME));
                $packageInfo['logo'] = 'upload/images/logo/' . $name . '/' . pathinfo($screenshot, PATHINFO_FILENAME);
            } else {
                $packageInfo['logo'] = '';
            }
        }
        $taxonomyController = $this->bridger->get(TaxonomyController::class);
        $taxonomies = [];
        if (!empty($packageInfo['keywords']) && is_array($packageInfo['keywords'])) {
            foreach ($packageInfo['keywords'] as $keyword) {
                if (!is_string($keyword)) {
                    continue;
                }
                $slug = Formatter::sanitizeWithDashes($keyword);
                $taxonomy = $taxonomyRepository->slug($slug, TermTaxonomy::TAG);
                if ($taxonomy == null) {
                    $taxonomy = new TermTaxonomy();
                    try {
                        $taxonomyController->save($taxonomy, [
                            'name' => $keyword,
                            'slug' => $slug,
                            'taxonomy' => TermTaxonomy::TAG,
                        ]);
                    } catch (\Exception $_) {
                        continue;
                    }
                }
                $taxonomies[] = $taxonomy;
            }
        }
        $content = '';
        if (file_exists($baseDir . '/README.md')) {
            $content = file_get_contents($baseDir . '/README.md');
        }
        if (file_exists($baseDir . '/readme.md')) {
            $content = file_get_contents($baseDir . '/readme.md');
        }
        if ($content) {
            $converter = new GithubFlavoredMarkdownConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
            $content = $converter->convert($content);
        }
        $data = [
            "title"  => $packageInfo['name'],
            "parent" => null,
            "author" => null,
            "content" => $content ? : $packageInfo['description'],
            "excerpt" => $packageInfo['description'],
            "name"    => $name,
            "status"  => "draft",
            "type"    => $type,
            "commentStatus" => "open",
            "pingStatus" => "open",
            "password" => "",
            "date" => null,
            "featuredImage" => null,
            "relationships" => array_map(function (TermTaxonomy $t) {
                return ['id' => $t->getId()];
            }, $taxonomies),
            "meta" => [
                'version'    => $packageInfo['version'],
                'entrypoint' => $packageInfo['entrypoint'],
                'logo'       => $packageInfo['logo'],
                'screenshot' => $packageInfo['screenshot'],
                'authors'    => $packageInfo['authors'],
                'homepage'   => $packageInfo['homepage'],
                'miniOP'     => $packageInfo['miniOP'],
                'miniPHP'    => $packageInfo['miniPHP'],
            ],
        ];
        $postController = $this->bridger->get(PostController::class);
        $postController->save($request, $package, $user, $data);
        $o = substr(md5($name . $_SERVER['APP_SECRET']), 0, 8);
        $packagePath = $assetDir . DIRECTORY_SEPARATOR . 'upload/files/'.$type.'/' . $o . '/'. $name;
        if (!file_exists($packagePath)) {
            $filesystem->mkdir($packagePath, 0755);
        }
        $filesystem->copy($zipFile, $packagePath . '/v' . $packageInfo['version']. '.zip');
        unlink($zipFile);
        return $package;
    }

}
