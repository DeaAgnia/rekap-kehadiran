<?php

namespace App\Http\Requests;

class StoreAttendanceRequest extends ApiFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'string', 'in:present,leave,duty,sick,permission,absent'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'note' => ['nullable', 'string', 'max:1000'],
            'attachment' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:5120',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_id' => 'Pegawai', 'attendance_date' => 'Tanggal kehadiran',
            'status' => 'Status kehadiran', 'check_in' => 'Jam masuk', 'check_out' => 'Jam pulang',
            'note' => 'Catatan', 'attachment' => 'Lampiran bukti',
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'attachment.mimes' => 'Lampiran harus berupa PDF, JPG, JPEG, atau PNG.',
            'attachment.mimetypes' => 'Isi lampiran harus berupa PDF atau gambar yang valid.',
            'attachment.max' => 'Ukuran lampiran maksimal 5 MB.',
        ];
    }
}
