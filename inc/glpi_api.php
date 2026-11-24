<?php
declare(strict_types=1);

/**
 * Cliente mínimo de la API REST de GLPI (apirest.php, GLPI 9.5 / 10.x).
 * Autenticación: App-Token + user_token  →  Session-Token.
 * Los tokens se leen del .env (config 'glpi'); nunca se muestran ni se guardan en la bitácora.
 */
final class GlpiApi
{
    private string $base;
    private ?string $session = null;

    public function __construct(private string $url, private ?string $appToken, private string $userToken,
                                private bool $verifySsl = true, private int $timeout = 20)
    {
        $u = rtrim(trim($url), '/');
        if (!preg_match('#/apirest\.php$#i', $u)) $u .= '/apirest.php';
        $this->base = $u;
    }

    public static function desdeConfig(): self
    {
        $c = cfg('glpi');
        if (empty($c['url']) || empty($c['user_token'])) {
            throw new RuntimeException('Faltan datos de conexión a GLPI (GLPI_URL y GLPI_USER_TOKEN en el archivo .env).');
        }
        return new self((string)$c['url'], $c['app_token'] ?: null, (string)$c['user_token'], (bool)$c['verify_ssl'], (int)$c['timeout']);
    }

    /** @return array{0:int,1:mixed} [código HTTP, cuerpo decodificado] */
    private function llamar(string $metodo, string $ruta, array $query = [], array $headers = []): array
    {
        $url = $this->base . '/' . ltrim($ruta, '/') . ($query ? '?' . http_build_query($query) : '');
        $h = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->appToken) $h[] = 'App-Token: ' . $this->appToken;
        if ($this->session) $h[] = 'Session-Token: ' . $this->session;
        foreach ($headers as $x) $h[] = $x;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'TrazaPulso/1.0',
        ]);
        $cuerpo = curl_exec($ch);
        $err = curl_error($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($cuerpo === false) throw new RuntimeException('No se pudo conectar con GLPI: ' . $err);
        $json = json_decode((string)$cuerpo, true);
        if ($codigo >= 400) {
            $msg = is_array($json) ? implode(' · ', array_map('strval', array_slice($json, 0, 2))) : mb_strimwidth(strip_tags((string)$cuerpo), 0, 200, '…');
            throw new RuntimeException("GLPI respondió $codigo: $msg");
        }
        return [$codigo, $json];
    }

    public function iniciarSesion(): void
    {
        [, $r] = $this->llamar('GET', 'initSession', [], ['Authorization: user_token ' . $this->userToken]);
        if (empty($r['session_token'])) throw new RuntimeException('GLPI no devolvió un token de sesión. Revisa los tokens.');
        $this->session = (string)$r['session_token'];
    }

    public function cerrarSesion(): void
    {
        if (!$this->session) return;
        try { $this->llamar('GET', 'killSession'); } catch (Throwable $e) { /* sin importancia */ }
        $this->session = null;
    }

    /** Datos del usuario con el que se conectó (para "Probar conexión"). */
    public function quienSoy(): array
    {
        [, $r] = $this->llamar('GET', 'getFullSession');
        $s = $r['session'] ?? [];
        return ['usuario' => $s['glpiname'] ?? '?', 'nombre' => trim(($s['glpifirstname'] ?? '') . ' ' . ($s['glpirealname'] ?? '')),
                'perfil' => $s['glpiactiveprofile']['name'] ?? '', 'entidad' => $s['glpiactive_entity_name'] ?? ''];
    }

    /**
     * Estado de varios tickets.
     * @param int[] $ids
     * @return array<int, array{status:int, name:string}> por id de ticket (los que no existen no aparecen)
     */
    public function estadosTickets(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $grupo) {
            $q = [];
            foreach ($grupo as $i => $id) {
                $q["items[$i][itemtype]"] = 'Ticket';
                $q["items[$i][items_id]"] = (int)$id;
            }
            [, $r] = $this->llamar('GET', 'getMultipleItems', $q);
            foreach ((array)$r as $t) {
                if (is_array($t) && isset($t['id'], $t['status'])) {
                    $texto = (string)($t['name'] ?? '') . ' ' . strip_tags(html_entity_decode(html_entity_decode((string)($t['content'] ?? ''))));
                    $out[(int)$t['id']] = ['status' => (int)$t['status'], 'name' => (string)($t['name'] ?? ''), 'texto' => mb_strtoupper($texto)];
                }
            }
        }
        return $out;
    }
}

/**
 * Consulta en GLPI el estado de todos los equipos con FOLIO GLPI y lo guarda como "Estado en GLPI (real)".
 * Solo registra en la bitácora los equipos cuyo estado cambió.
 */
function glpi_sincronizar(?int $uid, ?array $soloEquipos = null): array
{
    $t0 = microtime(true);
    $r = ['consultados' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'no_encontrados' => [], 'desconocidos' => [], 'error' => null];
    $filtro = $soloEquipos ? ' AND id IN (' . implode(',', array_map('intval', $soloEquipos) ?: [0]) . ')' : '';
    $equipos = q("SELECT id, folio_glpi, estado_glpi_real_id, serie, inventario, folio FROM equipos WHERE folio_glpi REGEXP '^[0-9]+$' $filtro")->fetchAll();
    $porTicket = [];
    foreach ($equipos as $e) $porTicket[(int)$e['folio_glpi']][] = $e;
    $mapa = q('SELECT codigo_glpi, id FROM estados_glpi WHERE codigo_glpi IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $api = null;
    try {
        if (!$porTicket) throw new RuntimeException($soloEquipos ? 'Este equipo no tiene FOLIO GLPI (numérico).' : 'No hay equipos con FOLIO GLPI. Aplica primero la sábana con la columna FOLIO GLPI.');
        $api = GlpiApi::desdeConfig();
        $api->iniciarSesion();
        $estados = $api->estadosTickets(array_keys($porTicket));
        $r['consultados'] = count($porTicket);
        db()->beginTransaction();
        foreach ($porTicket as $ticket => $lista) {
            if (!isset($estados[$ticket])) {
                $r['no_encontrados'][] = $ticket;
                foreach ($lista as $e) {
                    q('UPDATE equipos SET glpi_existe = 0, glpi_corresponde = NULL, glpi_titulo = NULL, glpi_consulta = NOW() WHERE id = ?', [$e['id']]);
                    if ($e['estado_glpi_real_id']) equipo_guardar((int)$e['id'], ['estado_glpi_real_id' => null], 'glpi', "El ticket $ticket no existe en GLPI (o sin permiso)", $uid);
                }
                continue;
            }
            foreach ($lista as $e) {
                q('UPDATE equipos SET glpi_existe = 1, glpi_corresponde = ?, glpi_titulo = ? WHERE id = ?',
                    [glpi_ticket_corresponde($estados[$ticket]['texto'], $e) ? 1 : 0, mb_substr($estados[$ticket]['name'], 0, 255), $e['id']]);
            }
            $cod = $estados[$ticket]['status'];
            if (!isset($mapa[$cod])) { $r['desconocidos'][$cod] = ($r['desconocidos'][$cod] ?? 0) + 1; continue; }
            foreach ($lista as $e) {
                if ((string)$e['estado_glpi_real_id'] === (string)$mapa[$cod]) {
                    $r['sin_cambios']++;
                } else {
                    equipo_guardar((int)$e['id'], ['estado_glpi_real_id' => (int)$mapa[$cod]], 'glpi', "Estado consultado en GLPI (ticket $ticket)", $uid);
                    $r['actualizados']++;
                }
                q('UPDATE equipos SET glpi_consulta = NOW() WHERE id = ?', [$e['id']]);
            }
        }
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $r['error'] = $ex->getMessage();
    } finally {
        $api?->cerrarSesion();
    }
    $r['segundos'] = round(microtime(true) - $t0, 1);
    q('INSERT INTO glpi_sync (usuario_id, consultados, actualizados, sin_cambios, no_encontrados, desconocidos, detalle, error, segundos) VALUES (?,?,?,?,?,?,?,?,?)', [
        $uid, $r['consultados'], $r['actualizados'], $r['sin_cambios'], count($r['no_encontrados']), array_sum($r['desconocidos']),
        json_encode(['no_encontrados' => array_slice($r['no_encontrados'], 0, 200), 'desconocidos' => $r['desconocidos']]), $r['error'], $r['segundos'],
    ]);
    return $r;
}

/** ¿Está configurada la conexión? */
function glpi_configurado(): bool
{
    $c = cfg('glpi');
    return !empty($c['url']) && !empty($c['user_token']);
}

/** Convierte una URL de regreso en una página local de la app (evita redirecciones externas). */
function glpi_volver(?string $url): string
{
    $u = parse_url((string)$url) ?: [];
    $pag = basename($u['path'] ?? '');
    if (!preg_match('/^[a-z_]+\.php$/', $pag)) return 'glpi.php';
    return $pag . (isset($u['query']) ? '?' . $u['query'] : '');
}

/**
 * Acepta la dirección como el usuario la copie del navegador:
 *  "glpi.empresa.com"                         → https://glpi.empresa.com
 *  "https://glpi.empresa.com/front/central.php" → https://glpi.empresa.com
 *  "http://10.0.0.5/glpi/front/ticket.php?id=3" → http://10.0.0.5/glpi
 */
function glpi_normalizar_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . ltrim($url, '/');
    $url = preg_replace('#[?\#].*$#', '', $url);                          // sin parámetros
    $url = preg_replace('#/(front|ajax|plugins|marketplace)(/.*)?$#i', '', $url); // sin pantallas internas
    $url = preg_replace('#/(index\.php|apirest\.php.*)$#i', '', $url);
    return rtrim($url, '/');
}

/**
 * Limpia los tokens tal como se peguen: quita etiquetas ("app_token=", "user_token=", "App-Token:", "Authorization: user_token")
 * y, si vienen cruzados (el de usuario en el campo de aplicación y viceversa), los acomoda.
 * @return array{0:?string,1:?string} [app_token, user_token]
 */
function glpi_limpiar_tokens(?string $app, ?string $user): array
{
    $tipo = function (?string &$t): ?string {
        if ($t === null) return null;
        $t = trim($t, " \t\r\n\"'");
        $k = null;
        if (preg_match('/^(?:authorization\s*:\s*)?(app[_-]?token|user[_-]?token)\s*[:=]?\s*(.+)$/i', $t, $m)) {
            $k = stripos($m[1], 'app') === 0 ? 'app' : 'user';
            $t = trim($m[2], " \t\"'");
        }
        return $k;
    };
    $ka = $tipo($app);
    $ku = $tipo($user);
    if ($ka === 'user' || $ku === 'app') [$app, $user] = [$user, $app];   // venían cruzados
    return [$app, $user];
}

/**
 * ¿El ticket de GLPI corresponde al equipo? Sí, si su título o descripción mencionan
 * la serie, el inventario o el folio (SCTASK) del equipo.
 */
function glpi_ticket_corresponde(string $textoTicket, array $e): bool
{
    $no = ['N/A', 'NA', 'ILEGIBLE', 'SIN SERIE', 'S/N', 'SN', '0'];
    foreach (['serie', 'inventario', 'folio'] as $k) {
        $v = mb_strtoupper(trim((string)($e[$k] ?? '')));
        if ($v === '' || in_array($v, $no, true) || mb_strlen($v) < 5) continue;
        if (str_contains($textoTicket, $v)) return true;
    }
    return false;
}
