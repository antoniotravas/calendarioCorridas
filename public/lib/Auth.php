<?php

declare(strict_types=1);

/**
 * Sessão do utilizador: quem está ligado, tokens CSRF, e o "exigir login".
 */
final class Auth
{
    /** Utilizador já lido da BD neste pedido (false = ainda não lido). */
    private array|null|false $utilizador = false;

    public function __construct(private readonly Database $db)
    {
    }

    public function utilizadorAtual(): ?array
    {
        if (!isset($_SESSION['utilizador_id'])) {
            return null;
        }

        if ($this->utilizador === false || (int) ($this->utilizador['id'] ?? 0) !== (int) $_SESSION['utilizador_id']) {
            $this->utilizador = $this->db->encontrarUtilizadorPorId((int) $_SESSION['utilizador_id']);
        }

        // Conta desativada (ou apagada) com a sessão ainda aberta: sai da conta.
        // (Só se tira o utilizador da sessão: a página pode já ter começado a sair.)
        if ($this->utilizador === null || !self::contaAtiva($this->utilizador)) {
            unset($_SESSION['utilizador_id']);
            $this->utilizador = false;
            return null;
        }

        return $this->utilizador;
    }

    /** Desativada pelo administrador na página "Gestão" (utilizadores.ativo = 0). */
    public static function contaAtiva(array $utilizador): bool
    {
        return (int) ($utilizador['ativo'] ?? 1) === 1;
    }

    /** Administrador: gere os utilizadores (/gestao-utilizadores.php). */
    public function eAdmin(): bool
    {
        return !empty($this->utilizadorAtual()['e_admin']);
    }

    /**
     * Pode ver os prémios monetários (ícone "€", filtro "Prémios" e secção na
     * página da prova): qualquer utilizador com sessão iniciada. Sem sessão, não.
     */
    public function podeVerPremios(): bool
    {
        return $this->utilizadorAtual() !== null;
    }

    /** Pode ver o ranking dos prémios (/ranking-premios.php): só administradores. */
    public function podeVerRanking(): bool
    {
        return $this->eAdmin();
    }

    /** Página só para administradores: login primeiro; sem permissão, 403. */
    public function exigirAdmin(string $destinoAposLogin = '/'): void
    {
        $this->exigirLogin($destinoAposLogin);
        if (!$this->eAdmin()) {
            http_response_code(403);
            die('Esta página é só para administradores.');
        }
    }

    public function autenticado(): bool
    {
        // Pela BD (e não só pela sessão), para que uma conta desativada saia logo.
        return $this->utilizadorAtual() !== null;
    }

    public function iniciarSessao(int $utilizadorId): void
    {
        session_regenerate_id(true);
        $_SESSION['utilizador_id'] = $utilizadorId;
        $this->utilizador = false;
    }

    public function terminarSessao(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->utilizador = false;
    }

    public function tokenCsrf(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function validarCsrf(?string $token): bool
    {
        return $token !== null
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Redireciona para o login se não houver sessão ativa.
     */
    public function exigirLogin(string $destinoAposLogin = '/'): void
    {
        if (!$this->autenticado()) {
            header('Location: /login.php?next=' . urlencode($destinoAposLogin));
            exit;
        }
    }

    /**
     * Garante que um destino de redireção é um caminho local (nunca outro site).
     */
    public static function caminhoSeguro(?string $caminho, string $padrao = '/'): string
    {
        if (!is_string($caminho) || $caminho === '' || $caminho[0] !== '/' || str_starts_with($caminho, '//')) {
            return $padrao;
        }

        return $caminho;
    }
}
