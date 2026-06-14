<?php
namespace Vapor\Incus;

/**
 * Gestione dei network forward di Incus (port forwarding lato rete).
 *
 * Un forward è associato a una rete e a un listen address; contiene una
 * lista di "ports" che inoltrano traffico verso target interni.
 * Riferimento: /1.0/networks/{network}/forwards
 */
class NetworkForwardService
{
    public function __construct(private IncusClient $incus) {}

    /**
     * Elenco delle reti disponibili (per popolare le select).
     */
    public function networks(): array
    {
        return $this->incus->get('/1.0/networks', ['recursion' => 1]);
    }

    /**
     * Elenco forward di una rete (recursion=1 = oggetti completi).
     */
    public function all(string $network): array
    {
        return $this->incus->get('/1.0/networks/' . rawurlencode($network) . '/forwards', ['recursion' => 1]);
    }

    /**
     * Dettaglio di un singolo forward per listen address.
     */
    public function find(string $network, string $listenAddress): array
    {
        return $this->incus->get(
            '/1.0/networks/' . rawurlencode($network) . '/forwards/' . rawurlencode($listenAddress)
        );
    }

    /**
     * Crea un forward.
     *
     * @param array{listen_address:string,description?:string,config?:array,ports?:array} $opts
     */
    public function create(string $network, array $opts): array
    {
        $body = [
            'listen_address' => $opts['listen_address'],
            'description'    => $opts['description'] ?? '',
            'config'         => $opts['config'] ?? new \stdClass(),
            'ports'          => $opts['ports'] ?? [],
        ];
        return $this->incus->post('/1.0/networks/' . rawurlencode($network) . '/forwards', $body);
    }

    /**
     * Sostituisce la definizione di un forward (PUT).
     */
    public function update(string $network, string $listenAddress, array $body): array
    {
        return $this->incus->put(
            '/1.0/networks/' . rawurlencode($network) . '/forwards/' . rawurlencode($listenAddress),
            $body
        );
    }

    /**
     * Aggiunge una singola regola di porta a un forward esistente.
     *
     * @param array{protocol:string,listen_port:string,target_address:string,target_port?:string} $port
     */
    public function addPort(string $network, string $listenAddress, array $port): array
    {
        $current = $this->find($network, $listenAddress);
        $ports   = $current['ports'] ?? [];
        $ports[] = $port;
        return $this->update($network, $listenAddress, [
            'description' => $current['description'] ?? '',
            'config'      => $current['config'] ?? new \stdClass(),
            'ports'       => $ports,
        ]);
    }

    public function delete(string $network, string $listenAddress): array
    {
        return $this->incus->delete(
            '/1.0/networks/' . rawurlencode($network) . '/forwards/' . rawurlencode($listenAddress)
        );
    }
}
