<?php
namespace Vapor\Incus;

/**
 * File manager: lettura/scrittura/elenco file dentro un'istanza Incus
 * tramite l'API /1.0/instances/{name}/files.
 *
 * Header rilevanti nella risposta:
 *   X-Incus-type : "file" | "directory" | "symlink"
 *   X-Incus-uid / X-Incus-gid / X-Incus-mode
 * Per le directory il corpo è l'envelope sync con l'elenco dei nomi.
 */
class FileService
{
    public function __construct(private IncusClient $incus) {}

    private function base(string $name): string
    {
        return '/1.0/instances/' . rawurlencode($name) . '/files';
    }

    /**
     * Elenca il contenuto di una directory, con il tipo di ogni voce.
     *
     * L'API Incus per le directory restituisce solo i nomi: per sapere se ogni
     * voce è file/cartella/symlink facciamo uno stat leggero (solo header).
     *
     * @return array{type:string,entries:array<int,array{name:string,path:string,type:string}>}
     */
    public function list(string $instance, string $path): array
    {
        [$status, $headers, $body] = $this->incus->rawWithHeaders(
            'GET', $this->base($instance), null, [], ['path' => $path]
        );
        if ($status >= 400) {
            throw new IncusException("Impossibile leggere $path (HTTP $status)", $status);
        }

        $type = $headers['x-incus-type'] ?? 'file';
        if ($type !== 'directory') {
            return ['type' => $type, 'entries' => []];
        }

        $data    = json_decode($body, true);
        $names   = $data['metadata'] ?? [];
        $entries = [];
        $prefix  = rtrim($path, '/') . '/';
        foreach ($names as $n) {
            $childPath = $prefix . $n;
            $entries[] = [
                'name' => $n,
                'path' => $childPath,
                'type' => $this->statType($instance, $childPath),
            ];
        }

        // Ordine: prima le cartelle, poi alfabetico (case-insensitive).
        usort($entries, function ($a, $b) {
            $ad = $a['type'] === 'directory';
            $bd = $b['type'] === 'directory';
            if ($ad !== $bd) {
                return $ad ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return ['type' => 'directory', 'entries' => $entries];
    }

    /**
     * Tipo di una entry ("directory" | "file" | "symlink") via stat leggero.
     */
    private function statType(string $instance, string $path): string
    {
        try {
            [$status, $headers] = $this->incus->headersOnly($this->base($instance), ['path' => $path]);
            if ($status >= 400) {
                return 'file';
            }
            return $headers['x-incus-type'] ?? 'file';
        } catch (\Throwable) {
            return 'file';
        }
    }

    /**
     * Legge il contenuto di un file e i suoi metadati.
     *
     * @return array{type:string,mode:string,uid:string,gid:string,content:string}
     */
    public function read(string $instance, string $path): array
    {
        [$status, $headers, $body] = $this->incus->rawWithHeaders(
            'GET', $this->base($instance), null, [], ['path' => $path]
        );
        if ($status >= 400) {
            throw new IncusException("Impossibile leggere $path (HTTP $status)", $status);
        }
        return [
            'type'    => $headers['x-incus-type'] ?? 'file',
            'mode'    => $headers['x-incus-mode'] ?? '',
            'uid'     => $headers['x-incus-uid'] ?? '0',
            'gid'     => $headers['x-incus-gid'] ?? '0',
            'content' => $body,
        ];
    }

    /**
     * Scrive (crea/sovrascrive) un file.
     */
    public function write(string $instance, string $path, string $content, string $mode = '0644'): void
    {
        [$status, , $body] = $this->incus->rawWithHeaders(
            'POST',
            $this->base($instance),
            $content,
            [
                'X-Incus-type: file',
                'X-Incus-mode: ' . $mode,
                'X-Incus-write: overwrite',
                'Content-Type: application/octet-stream',
            ],
            ['path' => $path]
        );
        if ($status >= 400) {
            throw new IncusException("Scrittura fallita su $path: $body", $status);
        }
    }

    /**
     * Crea una directory.
     */
    public function mkdir(string $instance, string $path, string $mode = '0755'): void
    {
        [$status, , $body] = $this->incus->rawWithHeaders(
            'POST',
            $this->base($instance),
            '',
            ['X-Incus-type: directory', 'X-Incus-mode: ' . $mode],
            ['path' => $path]
        );
        if ($status >= 400) {
            throw new IncusException("Creazione directory fallita: $body", $status);
        }
    }

    /**
     * Elimina un file o directory.
     */
    public function delete(string $instance, string $path): void
    {
        [$status, , $body] = $this->incus->rawWithHeaders(
            'DELETE', $this->base($instance), null, [], ['path' => $path]
        );
        if ($status >= 400) {
            throw new IncusException("Eliminazione fallita su $path: $body", $status);
        }
    }
}
