<?php

declare(strict_types=1);

namespace App\Tests\Unit\Env;

use App\Shared\Env\HerokuDatabaseUrlProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 14-C.2 — Vérifie que le processeur env `heroku_db` normalise
 * correctement les URLs Postgres injectées par Heroku ET reste
 * idempotent (appliqué N fois = même résultat).
 *
 * Tests purement unitaires : on n'instancie pas le container Symfony,
 * juste le processeur avec un `$getEnv` Closure factice.
 */
final class HerokuDatabaseUrlProcessorTest extends TestCase
{
    private HerokuDatabaseUrlProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new HerokuDatabaseUrlProcessor();
    }

    /**
     * Le `$getEnv` factice se comporte comme l'identité : il retourne
     * la valeur passée comme "name". En contexte réel, c'est lui qui
     * exécuterait la chaîne de processeurs internes (ex `resolve:`),
     * mais on n'a pas besoin de cette mécanique pour tester la
     * normalisation post-résolution.
     */
    private function identity(): \Closure
    {
        return static fn (string $name): string => $name;
    }

    public function testGetProvidedTypesExposesHerokuDb(): void
    {
        $types = HerokuDatabaseUrlProcessor::getProvidedTypes();

        self::assertArrayHasKey('heroku_db', $types);
        self::assertSame('string', $types['heroku_db']);
    }

    public function testHerokuFormatPostgresIsRewrittenAndCompleted(): void
    {
        $url = 'postgres://user:pass@host.amazonaws.com:5432/dbname';

        $out = $this->processor->getEnv('heroku_db', $url, $this->identity());

        // Schéma corrigé
        self::assertStringStartsWith('postgresql://', $out);
        // Identifiants + host + db préservés
        self::assertStringContainsString('user:pass@host.amazonaws.com:5432/dbname', $out);
        // Params ajoutés
        self::assertStringContainsString('sslmode=require', $out);
        self::assertStringContainsString('serverVersion=16', $out);
    }

    public function testAlreadyNormalizedUrlIsLeftUntouched(): void
    {
        $url = 'postgresql://user:pass@host:5432/dbname?sslmode=require&serverVersion=16';

        $out = $this->processor->getEnv('heroku_db', $url, $this->identity());

        // Même contenu logique : on ne re-duplique rien.
        self::assertSame(1, substr_count($out, 'sslmode='));
        self::assertSame(1, substr_count($out, 'serverVersion='));
        self::assertStringContainsString('sslmode=require', $out);
        self::assertStringContainsString('serverVersion=16', $out);
    }

    public function testDevStyleUrlWithServerVersionKeepsItAndAddsSslmode(): void
    {
        // Cas "dev qui poserait par erreur DATABASE_URL en prod" : le
        // processeur ne doit pas écraser serverVersion=16 ni charset=utf8.
        $url = 'postgresql://stayos_user:stayos_password@db:5432/stayos_db?serverVersion=16&charset=utf8';

        $out = $this->processor->getEnv('heroku_db', $url, $this->identity());

        // serverVersion conservé tel quel (pas dupliqué)
        self::assertSame(1, substr_count($out, 'serverVersion=16'));
        // charset préservé
        self::assertStringContainsString('charset=utf8', $out);
        // sslmode ajouté
        self::assertStringContainsString('sslmode=require', $out);
    }

    public function testOperatorOverridesAreRespected(): void
    {
        // Si l'opérateur a posé serverVersion=17 (PG17 bump) ou
        // sslmode=verify-full, on ne doit PAS écraser ses valeurs.
        $url = 'postgres://u:p@h:5432/db?sslmode=verify-full&serverVersion=17';

        $out = $this->processor->getEnv('heroku_db', $url, $this->identity());

        self::assertStringStartsWith('postgresql://', $out);
        self::assertStringContainsString('sslmode=verify-full', $out);
        self::assertStringNotContainsString('sslmode=require', $out);
        self::assertStringContainsString('serverVersion=17', $out);
        self::assertStringNotContainsString('serverVersion=16', $out);
    }

    public function testIdempotenceAfterTwoPasses(): void
    {
        $url = 'postgres://user:pass@host:5432/dbname';

        $once  = $this->processor->getEnv('heroku_db', $url,  $this->identity());
        $twice = $this->processor->getEnv('heroku_db', $once, $this->identity());

        self::assertSame($once, $twice, 'Le processeur doit être idempotent.');
    }

    public function testEmptyStringPassesThrough(): void
    {
        $out = $this->processor->getEnv('heroku_db', '', $this->identity());

        self::assertSame('', $out, 'Valeur vide retournée telle quelle (pas de crash au build).');
    }

    public function testParseUrlFallbackOnlyRewritesScheme(): void
    {
        // Mot de passe non URL-encodé contenant `@` : `parse_url` peut
        // soit échouer (false), soit produire un host tronqué. Dans tous
        // les cas, le fallback documenté = seulement le préfixe de schéma
        // est corrigé, le reste passe brut (pas de corruption).
        //
        // Le contrat testé n'est PAS "parse_url se débrouille bien",
        // c'est "même en cas de parse_url cassé, on ne corrompt rien
        // et on fait au moins le minimum (postgres:// → postgresql://)".
        $url = 'postgres://user:p@ss@word@host:5432/db';

        $out = $this->processor->getEnv('heroku_db', $url, $this->identity());

        self::assertStringStartsWith('postgresql://', $out, 'Schéma toujours corrigé.');
        // On ne teste pas sslmode/serverVersion ici : si parse_url a
        // donné un résultat exploitable, ils peuvent y être ; sinon
        // (fallback strict), pas. Les deux comportements sont
        // acceptables et documentés dans le docblock du processeur.
    }
}
