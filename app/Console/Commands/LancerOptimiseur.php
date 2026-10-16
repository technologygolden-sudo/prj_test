<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('optimiseur:lancer {--port=8090}')]
#[Description('Lance le service Python d’optimisation sur 127.0.0.1 avec le jeton de .env')]
class LancerOptimiseur extends Command
{
    public function handle(): int
    {
        $dossier = base_path('optimiseur');
        $python = PHP_OS_FAMILY === 'Windows' ? "{$dossier}\\.venv\\Scripts\\python.exe" : "{$dossier}/.venv/bin/python";
        $jeton = (string) config('services.optimiseur.jeton');

        if (! is_file($python)) {
            $this->error("Environnement Python absent : créez-le avec « python -m venv optimiseur/.venv » puis installez optimiseur/requirements.txt.");

            return self::FAILURE;
        }
        if ($jeton === '') {
            $this->error('OPTIMISEUR_JETON est vide dans .env.');

            return self::FAILURE;
        }

        $port = (string) (int) $this->option('port');
        $this->info("Service d’optimisation sur http://127.0.0.1:{$port} (Ctrl+C pour arrêter)");

        $process = new Process([$python, '-m', 'uvicorn', 'app:app', '--host', '127.0.0.1', '--port', $port], $dossier, [
            'OPTIMISEUR_JETON' => $jeton,
        ]);
        $process->setTimeout(null);

        return $process->run(fn ($type, $sortie) => $this->output->write($sortie));
    }
}
