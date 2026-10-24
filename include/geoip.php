<?php
/* =====================================================================
   GNL Solution — géolocalisation par base MaxMind GeoLite2-City (.mmdb)
   ---------------------------------------------------------------------
   Lecteur autonome du format MaxMind DB (aucune dépendance) : lecture à
   la demande par fseek/fread, adapté à un petit serveur (Raspberry Pi).
   Si l'extension PHP « maxminddb » est installée, elle est utilisée.

   gnl_geoip_lookup('81.2.69.142', '/chemin/GeoLite2-City.mmdb')
     -> array('country' => 'GB', 'sub' => 'ENG')   ou null
   ===================================================================== */

if (!class_exists('GnlMmdbReader')) {
class GnlMmdbReader {
    private $fh; private $nodeCount; private $recordSize; private $ipVersion;
    private $treeSize; private $dataStart; private $ipv4Start = null;

    public function __construct($path) {
        $this->fh = @fopen($path, 'rb');
        if (!$this->fh) throw new RuntimeException('mmdb: fichier illisible');
        $size = filesize($path);
        $marker = "\xAB\xCD\xEFMaxMind.com";
        $tail = min($size, 128 * 1024);
        fseek($this->fh, $size - $tail);
        $buf = fread($this->fh, $tail);
        $pos = strrpos($buf, $marker);
        if ($pos === false) throw new RuntimeException('mmdb: métadonnées introuvables');
        $metaStart = $size - $tail + $pos + strlen($marker);
        list($meta) = $this->decode($metaStart, $metaStart);
        $this->nodeCount  = (int) $meta['node_count'];
        $this->recordSize = (int) $meta['record_size'];
        $this->ipVersion  = (int) $meta['ip_version'];
        $this->treeSize   = (int) (($this->recordSize * 2 / 8) * $this->nodeCount);
        $this->dataStart  = $this->treeSize + 16;
    }
    public function __destruct() { if ($this->fh) fclose($this->fh); }

    private function read($offset, $len) {
        if ($len <= 0) return '';
        fseek($this->fh, $offset);
        $s = fread($this->fh, $len);
        return $s === false ? '' : $s;
    }
    private function uint($bytes) {
        $n = 0;
        for ($i = 0, $l = strlen($bytes); $i < $l; $i++) $n = ($n * 256) + ord($bytes[$i]);
        return $n;
    }
    private function readNode($node, $bit) {
        $rs = $this->recordSize;
        $base = $node * $rs * 2 / 8;
        if ($rs === 24) {
            return $this->uint($this->read($base + $bit * 3, 3));
        }
        if ($rs === 28) {
            $b = $this->read($base, 7);
            if ($bit === 0) return ((ord($b[3]) & 0xF0) << 20) | $this->uint(substr($b, 0, 3));
            return ((ord($b[3]) & 0x0F) << 24) | $this->uint(substr($b, 4, 3));
        }
        if ($rs === 32) {
            return $this->uint($this->read($base + $bit * 4, 4));
        }
        throw new RuntimeException('mmdb: taille d\'enregistrement inconnue');
    }
    public function get($ip) {
        $packed = @inet_pton($ip);
        if ($packed === false) return null;
        $bits = strlen($packed) * 8;
        $node = 0;
        if ($this->ipVersion === 6 && $bits === 32) {
            if ($this->ipv4Start === null) {
                $n = 0;
                for ($i = 0; $i < 96 && $n < $this->nodeCount; $i++) $n = $this->readNode($n, 0);
                $this->ipv4Start = $n;
            }
            $node = $this->ipv4Start;
        } elseif ($this->ipVersion === 4 && $bits === 128) {
            return null;
        }
        for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
            $bit = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
            $node = $this->readNode($node, $bit);
        }
        if ($node <= $this->nodeCount) return null; // == nodeCount : pas de donnée
        $offset = $this->treeSize + ($node - $this->nodeCount);
        list($val) = $this->decode($offset, $this->dataStart);
        return $val;
    }
    /* Décode une valeur à $offset ; $base = début de la section de données
       (les pointeurs y sont relatifs). Renvoie array(valeur, offset suivant). */
    private function decode($offset, $base) {
        $ctrl = ord($this->read($offset, 1)); $offset++;
        $type = $ctrl >> 5;
        if ($type === 1) { // pointeur
            $ss = ($ctrl >> 3) & 3; $vvv = $ctrl & 7;
            $b = $this->read($offset, $ss + 1); $offset += $ss + 1;
            if ($ss === 0)      $p = ($vvv << 8) | ord($b[0]);
            elseif ($ss === 1)  $p = (($vvv << 16) | $this->uint($b)) + 2048;
            elseif ($ss === 2)  $p = (($vvv << 24) | $this->uint($b)) + 526336;
            else                $p = $this->uint($b);
            list($v) = $this->decode($base + $p, $base);
            return array($v, $offset);
        }
        if ($type === 0) { $type = 7 + ord($this->read($offset, 1)); $offset++; }
        $size = $ctrl & 0x1F;
        if ($size >= 29) {
            $n = $size - 28;
            $b = $this->read($offset, $n); $offset += $n;
            if ($size === 29) $size = 29 + $this->uint($b);
            elseif ($size === 30) $size = 285 + $this->uint($b);
            else $size = 65821 + $this->uint($b);
        }
        switch ($type) {
            case 2:  return array($this->read($offset, $size), $offset + $size);                 // utf8
            case 3:  $v = unpack('E', $this->read($offset, 8)); return array($v[1], $offset + 8); // double
            case 4:  return array($this->read($offset, $size), $offset + $size);                 // octets
            case 5: case 6: case 9: case 10:
                return array($this->uint($this->read($offset, $size)), $offset + $size);          // entiers non signés
            case 8:  $u = $this->uint($this->read($offset, $size));
                     if ($size === 4 && $u >= 0x80000000) $u -= 0x100000000;
                     return array($u, $offset + $size);                                          // int32
            case 7:  $m = array();                                                                // map
                     for ($i = 0; $i < $size; $i++) {
                         list($k, $offset) = $this->decode($offset, $base);
                         list($v, $offset) = $this->decode($offset, $base);
                         $m[$k] = $v;
                     }
                     return array($m, $offset);
            case 11: $a = array();                                                                // tableau
                     for ($i = 0; $i < $size; $i++) { list($v, $offset) = $this->decode($offset, $base); $a[] = $v; }
                     return array($a, $offset);
            case 14: return array($size !== 0, $offset);                                          // booléen
            case 15: $v = unpack('G', $this->read($offset, 4)); return array($v[1], $offset + 4); // float
            default: return array(null, $offset + $size);
        }
    }
}
}

if (!function_exists('gnl_geoip_lookup')) {
    function gnl_geoip_lookup($ip, $dbPath) {
        if ($ip === '' || !is_readable($dbPath)) return null;
        try {
            if (class_exists('MaxMind\\Db\\Reader')) {
                $cls = 'MaxMind\\Db\\Reader';
                $r = new $cls($dbPath);
                $rec = $r->get($ip);
                $r->close();
            } else {
                $r = new GnlMmdbReader($dbPath);
                $rec = $r->get($ip);
            }
        } catch (Exception $e) {
            error_log('[GNL geoip] ' . $e->getMessage());
            return null;
        }
        if (!is_array($rec) || empty($rec['country']['iso_code'])) return null;
        $sub = '';
        if (!empty($rec['subdivisions'][0]['iso_code'])) $sub = (string) $rec['subdivisions'][0]['iso_code'];
        return array('country' => strtoupper((string) $rec['country']['iso_code']), 'sub' => strtoupper($sub));
    }
}
