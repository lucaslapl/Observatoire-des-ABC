<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Audit anti-PII : parcourt toutes les réponses JSON de l'API v1 avec des
 * fixtures pleines de données personnelles (e-mail, IP, noms, identifiant
 * utilisateur, user-agent) et échoue s'il en retrouve une seule dans les
 * réponses — y compris dans les relations chargées.
 */
class ApiV1PiiAuditTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'contributeur.pii@example.org';

    private const NOM_CONTRIBUTEUR = 'Prénom Nomcontributeur';

    private const NOM_ADMIN = 'Admin Operateur';

    private const IP = '203.0.113.42';

    private const USER_AGENT = 'Mozilla/5.0 (X11; PII Audit)';

    private Projet $projet;

    private User $contributeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contributeur = User::factory()->create([
            'name' => self::NOM_CONTRIBUTEUR,
            'email' => self::EMAIL,
        ]);

        $this->projet = Projet::create([
            'id' => 'abc-pii',
            'nom' => 'ABC de Vannes',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
        ]);

        Commune::create([
            'projet_id' => $this->projet->id,
            'code_geographique' => '56260',
            'libelle_geographique' => 'Vannes',
            'departement' => '56',
            'libelle_departement' => 'Morbihan',
            'region' => 'Bretagne',
            'anomalie' => false,
            'lon' => 2.76,
            'lat' => 47.66,
        ]);

        Verification::create([
            'projet_id' => $this->projet->id,
            'etat' => 'confirme_termine',
            'note' => 'Vérifié sur le site de la mairie.',
            'verifie_par' => self::NOM_ADMIN,
        ]);

        $contribution = Contribution::create([
            'projet_id' => $this->projet->id,
            'user_id' => $this->contributeur->id,
            'type' => 'statut',
            'payload_json' => ['statut' => 'termine'],
            'commentaire' => 'Le statut sur le site de la mairie est « terminé ».',
            'ip' => self::IP,
            'user_agent' => self::USER_AGENT,
            'statut' => 'validee',
            'traite_par' => self::NOM_ADMIN,
        ]);

        AuditLog::create([
            'contribution_id' => $contribution->id,
            'action' => 'validee',
            'apres' => json_encode(['projet_id' => $this->projet->id, 'statut' => 'termine']),
            'par_admin' => self::NOM_ADMIN,
        ]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function endpointsProvider(): array
    {
        return [
            ['/api/v1/projets'],
            ['/api/v1/projets?format=geojson'],
            ['/api/v1/projets/abc-pii'],
            ['/api/v1/communes/56260'],
            ['/api/v1/communes/56260?format=geojson'],
            ['/api/v1/departements/56'],
            ['/api/v1/regions/bretagne'],
            ['/api/v1/journal'],
            ['/api/v1/stats'],
            ['/api/v1/communes/56260/patrimoine'],
            ['/api/v1/patrimoine/znieff'],
            ['/api/v1/patrimoine/natura'],
        ];
    }

    #[DataProvider('endpointsProvider')]
    public function test_aucune_donnee_personnelle_dans_les_reponses(string $url): void
    {
        $response = $this->getJson($url);
        $response->assertOk();

        $content = $response->getContent();

        // Identifiants directs : e-mail, IP, noms, user-agent, clés user.
        $this->assertStringNotContainsString(self::EMAIL, $content, "PII (email) trouvée dans {$url}");
        $this->assertStringNotContainsString(self::IP, $content, "PII (IP) trouvée dans {$url}");
        $this->assertStringNotContainsString(self::NOM_CONTRIBUTEUR, $content, "PII (nom contributeur) trouvée dans {$url}");
        $this->assertStringNotContainsString(self::NOM_ADMIN, $content, "PII (nom admin) trouvée dans {$url}");
        $this->assertStringNotContainsString('user_agent', $content, "PII (user_agent) trouvée dans {$url}");
        $this->assertStringNotContainsString('user_id', $content, "PII (user_id) trouvée dans {$url}");
        $this->assertStringNotContainsString('traite_par', $content, "PII (traite_par) trouvée dans {$url}");
        $this->assertStringNotContainsString('verifie_par', $content, "PII (verifie_par) trouvée dans {$url}");

        // Balayage générique : n'importe quel e-mail ou IPv4 dans la réponse.
        $this->assertDoesNotMatchRegularExpression(
            '/[\w.+-]+@[\w-]+(\.[\w-]+)+/',
            $content,
            "Un e-mail apparaît dans la réponse de {$url}"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
            $content,
            "Une adresse IP apparaît dans la réponse de {$url}"
        );

        // Le crédit est bien collectif, jamais nominatif.
        $this->assertStringNotContainsString('Prénom', $content);
    }
}
