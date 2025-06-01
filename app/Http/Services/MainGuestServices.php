<?php

namespace App\Http\Services;

use App\Http\Resources\MainGuestResource;
use App\Models\MainGuest;
use App\Models\PartyMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class MainGuestServices
{
    protected Request $request;

    private MainGuest $mainGuest;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->mainGuest = new MainGuest;
    }

    /**
     * Get main guest resource.
     */
    public function getMainGuest(MainGuest $mainGuest): MainGuestResource
    {
        return MainGuestResource::make($mainGuest);
    }

    /**
     * Get All main guests.
     */
    public function getMainGuestWithPagination(): AnonymousResourceCollection
    {
        $search = $this->request->input('search') ?? '';
        $perPage = $this->request->input('itemsPerPage') ?? 25;
        $confirmedStatus = $this->request->input('confirmedStatus');

        $mainGuests = MainGuest::query()->with(['partyMembers']);

        if ($search) {
            $mainGuests = $mainGuests->where(function ($query) use ($search) {
                $query->where('first_name', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%");
            });
        }

        if ($confirmedStatus && $confirmedStatus !== 'select') {
            if ($confirmedStatus === 'ny') {
                $mainGuests = $mainGuests->where('confirmed', 'unused');
            } else {
                $mainGuests = $mainGuests->where('confirmed', $confirmedStatus)
                    ->orWhereHas('partyMembers', function ($query) use ($confirmedStatus) {
                        $query->where('confirmed', $confirmedStatus);
                    });
            }
        }

        return MainGuestResource::collection($mainGuests->paginate($perPage));
    }

    /**
     * Create main guest, function.
     *
     * @throws \Exception
     */
    public function create(): Model|Builder
    {
        $mainGuest = MainGuest::query()->create([
            'first_name' => $this->request->input('firstName'),
            'last_name' => $this->request->input('lastName'),
            'email' => $this->request->input('email') ?? '',
            'phone_number' => $this->request->input('phoneNumber'),
            'confirmed' => 'unused',
            'confirmed_date' => null,
            'access_code' => $this->calculateAccessCode(),
        ]);

        if (! $mainGuest) {
            throw new \Exception('Error creating the main guest!');
        }

        $partyMembers = json_decode($this->request->input('partyMembers'));
        if (count(($partyMembers))) {
            foreach ($partyMembers as $member) {
                PartyMember::query()->create([
                    'main_guest_id' => $mainGuest->id,
                    'name' => $member->name,
                    'confirmed' => 'unused',
                ]);
            }
        }

        return $mainGuest;
    }

    /**
     * Auto generate access code.
     */
    private function calculateAccessCode(): string
    {
        $code = Str::upper(Str::substr($this->request->input('firstName'), 0, 1));

        $code .= Str::upper(Str::substr($this->request->input('lastName'), 0, 1));

        $code .= Str::upper(Str::substr($this->request->input('phoneNumber'), -2));

        return $code;
    }

    /**
     * Update Main guest info;
     *
     * @return Collection|Builder|Builder[]|Model
     */
    public function update(MainGuest $mainGuest): Builder|array|Collection|Model
    {
        $this->mainGuest = $mainGuest;

        $this->mainGuest->first_name = $this->request->input('firstName');
        $this->mainGuest->last_name = $this->request->input('lastName');
        $this->mainGuest->email = $this->request->input('email');
        $this->mainGuest->phone_number = $this->request->input('phoneNumber');

        PartyMember::query()
            ->where('main_guest_id', $this->mainGuest->id)
            ->delete();

        $partyMembers = json_decode($this->request->input('partyMembers'));
        if (count($partyMembers)) {
            foreach ($partyMembers as $member) {
                PartyMember::query()->create([
                    'main_guest_id' => $this->mainGuest->id,
                    'name' => $member->name,
                    'confirmed' => $member->confirmed,
                ]);
            }
        }
        $this->mainGuest->save();

        return MainGuest::query()->find($this->mainGuest->id);
    }

    /**
     * Delete Main Guest from db.
     */
    public function destroy(MainGuest $mainGuest): MainGuest
    {
        $mainGuestSaved = clone $mainGuest;

        PartyMember::query()
            ->where('main_guest_id', $mainGuest->id)->delete();

        $mainGuest->delete();

        return $mainGuestSaved;
    }
}
