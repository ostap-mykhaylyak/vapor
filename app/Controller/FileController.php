<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;

/**
 * File manager dentro un'istanza Incus.
 */
class FileController extends Controller
{
    /** Browser: elenco di una directory. */
    public function browse(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);
        $path     = $this->normalize($request->input('path', '/'));
        $listing  = $this->files->list($instance, $path);

        if ($request->wantsJson()) {
            return $this->json(['path' => $path] + $listing);
        }
        return $this->view('files/index', [
            'instance' => $instance,
            'path'     => $path,
            'parent'   => $this->parent($path),
            'listing'  => $listing,
        ]);
    }

    /** Visualizza/edita un file. */
    public function read(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);
        $path     = $this->normalize($request->input('path', '/'));
        $file     = $this->files->read($instance, $path);

        // Se è una directory (link cliccato per errore), torna al browser.
        if (($file['type'] ?? '') === 'directory') {
            return $this->redirect('/instances/' . rawurlencode($instance) . '/files?path=' . rawurlencode($path));
        }

        // File binario: niente modifica (evita corruzione al salvataggio).
        $binary = str_contains($file['content'], "\0");

        if ($request->wantsJson()) {
            return $this->json(['path' => $path, 'binary' => $binary] + $file);
        }
        return $this->view('files/edit', [
            'instance' => $instance,
            'path'     => $path,
            'parent'   => $this->parent($path),
            'file'     => $file,
            'binary'   => $binary,
        ]);
    }

    /** Scrive il contenuto di un file. */
    public function write(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);
        $data     = $request->wantsJson() ? $request->json() : $request->post;
        $path     = $this->normalize($data['path'] ?? '/');

        $this->files->write($instance, $path, (string)($data['content'] ?? ''), $data['mode'] ?? '0644');
        $this->audit('file.write', $instance, $path);

        return $request->wantsJson()
            ? $this->json(['ok' => true])
            : $this->redirect('/instances/' . rawurlencode($instance) . '/files?path=' . rawurlencode($this->parent($path)));
    }

    /** Crea una directory. */
    public function mkdir(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);
        $data     = $request->wantsJson() ? $request->json() : $request->post;
        $path     = $this->normalize($data['path'] ?? '/');

        $this->files->mkdir($instance, $path);
        $this->audit('file.mkdir', $instance, $path);

        return $request->wantsJson()
            ? $this->json(['ok' => true], 201)
            : $this->redirect('/instances/' . rawurlencode($instance) . '/files?path=' . rawurlencode($this->parent($path)));
    }

    /** Upload di un file. */
    public function upload(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);
        $dir      = $this->normalize($request->input('path', '/'));

        if (empty($_FILES['file']['tmp_name'])) {
            return $this->json(['error' => 'Nessun file caricato'], 400);
        }
        $name    = basename($_FILES['file']['name']);
        $content = file_get_contents($_FILES['file']['tmp_name']) ?: '';
        $target  = rtrim($dir, '/') . '/' . $name;

        $this->files->write($instance, $target, $content);
        $this->audit('file.upload', $instance, $target);

        return $request->wantsJson()
            ? $this->json(['ok' => true], 201)
            : $this->redirect('/instances/' . rawurlencode($instance) . '/files?path=' . rawurlencode($dir));
    }

    /** Elimina file o directory. */
    public function destroy(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);
        $data     = $request->wantsJson() ? $request->json() : $request->post;
        $path     = $this->normalize($data['path'] ?? '');

        $this->files->delete($instance, $path);
        $this->audit('file.delete', $instance, $path);

        return $request->wantsJson()
            ? Response::noContent()
            : $this->redirect('/instances/' . rawurlencode($instance) . '/files?path=' . rawurlencode($this->parent($path)));
    }

    /* --- Helper sui path --- */

    private function normalize(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        // Risolve i segmenti scartando qualsiasi '..' (anti traversal robusto).
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }

    private function parent(string $path): string
    {
        $parent = rtrim(dirname($path), '/');
        return $parent === '' ? '/' : $parent;
    }
}
