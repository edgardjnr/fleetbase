<?php

// Dublê do LiderDosMotoboys para os testes das rotas do motoboy (o real precisa do User e do CompanyUser do core; tem
// teste próprio em lider.php). Defina Teste\Lider::$lider = true para a sessão ser de líder.

namespace Teste {
    class Lider { public static bool $lider = false; }
}

namespace App\Support\Entregas {
    class LiderDosMotoboys
    {
        public static function daSessao($request): bool { return \Teste\Lider::$lider; }
    }
}
