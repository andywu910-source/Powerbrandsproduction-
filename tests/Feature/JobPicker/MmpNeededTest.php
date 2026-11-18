<?php

namespace Tests\Feature\JobPicker;

use App\Models\Mmp;
use App\Models\Product;
use App\Services\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase1Fixture;
use Tests\TestCase;

/** Start a batch with a product that has no approved MMP: say so plainly and link to adding / approving it (Andy, Nov 17). */
class MmpNeededTest extends TestCase
{
    use Phase1Fixture, RefreshDatabase;

    public function test_no_mmp_says_so_and_links_to_add_one_prefilled(): void
    {
        $this->buildPlant();
        $new = $this->product->replicate(['code', 'short_code']);
        $new->fill(['code' => 'mm-straw-lava', 'name' => 'Strawberry Lava 1g Cart', 'short_code' => 'SLV'])->save();
        $page = $this->actingAs($this->andy)->get('/production?product_id='.$new->id)->assertOk()->getContent();
        $this->assertStringContainsString('This product has no approved MMP yet. Add and approve one to start a batch.', $page);
        $this->assertStringContainsString(e(route('plans.index', ['product_id' => $new->id])).'#newMmp', $page);
        $this->assertStringNotContainsString('id="btnStart"', $page, 'the MMP rule itself is unchanged');
        $this->post('/production', ['product_id' => $new->id, 'target_units' => 10, 'room_id' => $this->room->id, 'batch_number' => 'X-1'])->assertSessionHasErrors();

        $this->actingAs($this->andy)->get('/setup/plans?product_id='.$new->id)->assertOk()
            ->assertSee('New MMP for Strawberry Lava 1g Cart')->assertSee('<option value="'.$new->id.'" selected', false);
        $this->actingAs($this->op)->get('/production?product_id='.$new->id)->assertOk()->assertSee('Ask an admin or supervisor')->assertDontSee('id="mmpNeededLink"', false);

        $d = Mmp::create(['public_id' => PublicId::next('MMP'), 'product_id' => $new->id, 'version' => 1, 'title' => 'x', 'basis_units' => 100, 'status' => 'draft']);
        $this->actingAs($this->andy)->get('/production?product_id='.$new->id)->assertOk()
            ->assertSee('has an MMP that is not approved yet ('.$d->public_id.' v1, draft)', false)->assertSee(route('plans.mmp', $d).'#approve', false);
        $this->actingAs($this->andy)->get(route('plans.mmp', $d))->assertOk()->assertSee('Before it can be approved:')->assertSee('Add at least one ingredient');

        // an approved recipe offers the way back to Start a batch, and the start form is there
        $this->actingAs($this->andy)->get(route('plans.mmp', $this->mmp))->assertOk()->assertSee('id="startFromMmp"', false);
        $this->actingAs($this->andy)->get('/production?product_id='.$this->product->id)->assertOk()->assertSee('id="btnStart"', false)->assertDontSee('id="mmpNeeded"', false);
    }
}
