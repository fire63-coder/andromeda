<?php

namespace Tests\Feature\Admin;

use App\Enums\DatasetRole;
use App\Enums\DatasetStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\Datasets\DatasetImportWizard;
use App\Livewire\Admin\Datasets\DatasetShow;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\User;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class DatasetImportTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();
        Storage::fake('local');

        $this->trainer = User::factory()->create();
        $this->trainer->forceFill(['role' => UserRole::Trainer])->save();
        $this->actingAs($this->trainer);
    }

    private function csv(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function sqlite(): SqlDialect
    {
        return SqlDialect::where('slug', 'sqlite')->firstOrFail();
    }

    #[Test]
    public function a_csv_import_builds_a_queryable_dataset_with_inferred_keys(): void
    {
        Livewire::test(DatasetImportWizard::class)
            ->set('name', 'Ressources humaines')
            ->assertSet('slug', 'ressources-humaines')
            ->set('format', 'csv')
            ->set('files', [
                $this->csv('Departements.csv', "id;Nom\n1;Ventes\n2;R&D\n"),
                $this->csv('employes.csv', "id,nom,departement_id,salaire,embauche_le\n1,Alice,1,\"3200,50\",2021-03-01\n2,Bruno,2,4100,2019-09-15\n3,Chloé,2,3900,2023-01-09\n"),
            ])
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->assertSee('departements')
            ->assertSee('→ departements.id')
            ->set('renames.employes', 'employees')
            ->call('import')
            ->assertRedirect(route('admin.datasets.show', 'ressources-humaines'));

        $dataset = Dataset::where('slug', 'ressources-humaines')->firstOrFail();
        $this->assertSame(DatasetStatus::Ready, $dataset->status);
        $this->assertSame(['departements', 'employees'], array_column($dataset->tables_meta, 'name'));
        $this->assertSame(5, $dataset->total_rows);
        $this->assertSame(DatasetStatus::Ready, $dataset->imports()->first()->status);
        $this->assertSame(DatasetStatus::Ready, $dataset->builds()->where('sql_dialect_id', $this->sqlite()->id)->value('status'));

        $result = app(SandboxManager::class)->run($dataset, $this->sqlite(),
            'SELECT d.nom, SUM(e.salaire) FROM employees e JOIN departements d ON d.id = e.departement_id GROUP BY d.nom ORDER BY d.nom');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertEquals([['R&D', 8000], ['Ventes', 3200.5]], $result->rows);
    }

    #[Test]
    public function a_json_file_can_contain_several_tables(): void
    {
        Livewire::test(DatasetImportWizard::class)
            ->set('name', 'Bibliothèque')
            ->set('format', 'json')
            ->set('files', [UploadedFile::fake()->createWithContent('biblio.json', json_encode([
                'authors' => [['id' => 1, 'name' => 'Ursula K. Le Guin'], ['id' => 2, 'name' => 'Ted Chiang']],
                'books' => [
                    ['id' => 1, 'title' => 'La Main gauche de la nuit', 'author_id' => 1, 'tags' => ['sf', 'classique'], 'available' => true],
                    ['id' => 2, 'title' => 'La Tour de Babylone', 'author_id' => 2, 'available' => false],
                ],
            ]))])
            ->call('analyze')
            ->assertSet('step', 2)
            ->call('import');

        $books = collect(Dataset::where('slug', 'bibliotheque')->firstOrFail()->tables_meta)->firstWhere('name', 'books');
        $columns = collect($books['columns'])->keyBy('name');

        $this->assertSame('authors.id', $columns['author_id']['references']);
        $this->assertSame('BOOLEAN', $columns['available']['type']);
        $this->assertTrue($columns['tags']['nullable']);
    }

    #[Test]
    public function a_portable_sql_dump_keeps_its_declared_schema(): void
    {
        Livewire::test(DatasetImportWizard::class)
            ->set('name', 'Dump')
            ->set('format', 'sql_dump')
            ->set('files', [UploadedFile::fake()->createWithContent('dump.sql', <<<'SQL'
                CREATE TABLE villes (id INTEGER PRIMARY KEY, nom VARCHAR(80) NOT NULL, code TEXT);
                CREATE TABLE habitants (id INTEGER PRIMARY KEY, ville_id INTEGER REFERENCES villes(id), revenu DECIMAL(10,2));
                INSERT INTO villes VALUES (1, 'Lyon', '69000'), (2, 'Nantes', '44000');
                INSERT INTO habitants VALUES (1, 1, 2100.50), (2, 2, NULL);
                SQL)])
            ->call('analyze')
            ->assertSet('step', 2)
            ->call('import');

        $dataset = Dataset::where('slug', 'dump')->firstOrFail();
        $code = collect($dataset->tables_meta[0]['columns'])->firstWhere('name', 'code');
        $this->assertSame('VARCHAR(255)', $code['type']); // « 69000 » reste du texte
        $this->assertSame('villes.id', $dataset->tables_meta[1]['columns'][1]['references']);
        $this->assertSame([['Lyon']], app(SandboxManager::class)->run($dataset, $this->sqlite(), "SELECT nom FROM villes WHERE code = '69000'")->rows);
    }

    #[Test]
    public function a_malicious_dump_is_rejected_during_analysis(): void
    {
        $target = sys_get_temp_dir().'/andromeda-import-'.getmypid().'.php';

        Livewire::test(DatasetImportWizard::class)
            ->set('name', 'Piège')
            ->set('format', 'sql_dump')
            ->set('files', [UploadedFile::fake()->createWithContent('evil.sql', "CREATE TABLE t (x TEXT);\nATTACH DATABASE '{$target}' AS evil;")])
            ->call('analyze')
            ->assertHasErrors('files')
            ->assertSee('Dump refusé')
            ->assertSet('step', 1);

        $this->assertFileDoesNotExist($target);
        $this->assertSame(0, Dataset::where('slug', 'piege')->count());
    }

    #[Test]
    public function invalid_files_are_reported_to_the_author(): void
    {
        Livewire::test(DatasetImportWizard::class)
            ->set('name', 'Cassé')
            ->set('format', 'json')
            ->set('files', [UploadedFile::fake()->createWithContent('broken.json', '{"a": 1}')])
            ->call('analyze')
            ->assertHasErrors('files')
            ->assertSee('Le JSON doit être une liste d');
    }

    #[Test]
    public function students_cannot_reach_the_back_office(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('admin.datasets.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.datasets.import'))->assertForbidden();
        $this->actingAs($this->trainer)->get(route('admin.datasets.index'))->assertOk()->assertSee('Boutique en ligne');
    }

    #[Test]
    public function datasets_can_be_linked_to_exercises_as_hidden_tests(): void
    {
        $dataset = Dataset::where('slug', 'boutique')->firstOrFail();
        $dataset->update(['created_by' => $this->trainer->id]);
        $exercise = Exercise::where('slug', 'qcm-filtrer-des-groupes')->firstOrFail();
        $sqlExercise = Exercise::where('slug', 'clients-de-lyon')->firstOrFail();

        Livewire::test(DatasetShow::class, ['dataset' => $dataset])
            ->assertSee('Les clients lyonnais')
            ->set('exerciseId', $exercise->id)
            ->set('role', DatasetRole::HiddenTest->value)
            ->call('attachExercise')
            ->assertHasNoErrors()
            ->assertSee('QCM : filtrer des groupes');

        $this->assertSame(DatasetRole::HiddenTest->value, $exercise->datasets()->first()->pivot->role);

        // Un exercice n'a qu'un seul jeu visible.
        $other = Dataset::where('slug', 'boutique-tests')->firstOrFail();
        $other->update(['created_by' => $this->trainer->id]);
        $sqlExercise->datasets()->detach($other->id);

        Livewire::test(DatasetShow::class, ['dataset' => $other])
            ->set('exerciseId', $sqlExercise->id)
            ->set('role', DatasetRole::Primary->value)
            ->call('attachExercise')
            ->assertHasErrors('exerciseId');
    }

    #[Test]
    public function the_show_page_previews_table_data_from_the_sandbox(): void
    {
        Livewire::test(DatasetShow::class, ['dataset' => Dataset::where('slug', 'boutique')->firstOrFail()])
            ->assertSee('Alice Martin')
            ->call('$set', 'previewTable', 'products')
            ->assertSee('Clavier mécanique');
    }
}
