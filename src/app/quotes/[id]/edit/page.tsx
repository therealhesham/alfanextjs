import React from 'react';
import { prisma } from '@/lib/prisma';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { updateQuoteDetails } from '@/app/actions/quoteActions';

export default async function EditQuotePage({ params }: { params: Promise<{ id: string }> }) {
  const resolvedParams = await params;
  const quoteId = parseInt(resolvedParams.id, 10);
  
  if (isNaN(quoteId)) {
    return <div className="p-8 text-center text-red-500">رقم عرض سعر غير صحيح</div>;
  }

  const quote = await prisma.elevator_quotes.findUnique({
    where: { id: BigInt(quoteId) },
    include: {
      users_elevator_quotes_client_user_idTousers: true,
    }
  });

  if (!quote) {
    return <div className="p-8 text-center">لم يتم العثور على عرض السعر</div>;
  }

  const updateQuoteAction = async (formData: FormData) => {
    'use server';
    const dataObj = Object.fromEntries(formData.entries());
    const res = await updateQuoteDetails(quoteId.toString(), dataObj);
    if (res.success) {
      redirect(`/quotes/${quoteId}`);
    } else {
      // In a real app we'd handle error visually, but server action throwing is fine for now
      throw new Error(res.error || 'Failed to update');
    }
  };

  return (
    <div className="max-w-5xl mx-auto p-3 md:p-6 min-h-screen bg-[var(--color-light-gray)]" dir="rtl">
      <div className="card mb-6">
        <div className="flex flex-col md:flex-row justify-between items-center mb-6 border-b border-[var(--color-border)] pb-4 gap-4">
          <h1 className="text-xl md:text-2xl font-bold text-[var(--color-dark-gray)] flex items-center gap-3">
            <i className="fas fa-edit text-[var(--color-gold)] text-lg md:text-xl"></i>
            تعديل عرض السعر #{quote.quote_number?.toString() || quote.id.toString()}
          </h1>
          <div className="flex gap-3 w-full md:w-auto">
            <Link href={`/quotes/${quoteId}`} className="btn-gold w-full md:w-auto text-center justify-center">
              <i className="fas fa-eye"></i>
              معاينة
            </Link>
            <Link href="/" className="btn-gray w-full md:w-auto text-center justify-center">
              <i className="fas fa-arrow-right"></i>
              رجوع
            </Link>
          </div>
        </div>

        <form className="space-y-8" action={updateQuoteAction}>
          <div className="bg-[var(--color-gold-light)] border border-[var(--color-gold)] p-4 rounded-lg mb-6 flex items-start gap-3">
            <i className="fas fa-info-circle text-[var(--color-gold)] mt-1"></i>
            <p className="text-[var(--color-dark-gray)] font-medium">
              جميع الحقول قابلة للتعديل ما عدا اسم العميل (للحفاظ على مرجعية العرض).
            </p>
          </div>

          {/* معلومات العميل (غير قابلة للتعديل) */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg bg-[#f8f9fa]">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
              <i className="fas fa-user text-[var(--color-gold)]"></i> بيانات العميل
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">اسم العميل</label>
                <input 
                  type="text" 
                  className="w-full bg-gray-200 text-gray-500 border border-[var(--color-border)] rounded-md shadow-sm p-2.5 cursor-not-allowed" 
                  defaultValue={quote.users_elevator_quotes_client_user_idTousers?.name || 'غير محدد'}
                  readOnly
                  disabled
                />
              </div>
            </div>
          </div>

          {/* المواصفات الأساسية */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">المواصفات الأساسية والماكينة</h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد المصاعد</label>
                <input type="number" name="number_of_elevators" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.number_of_elevators} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">نوع الماكينة</label>
                <input type="text" name="machine_type" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.machine_type || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">مكان الماكينة</label>
                <input type="text" name="machine_position" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.machine_position || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد الوقفات</label>
                <input type="number" name="number_of_stops" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.number_of_stops || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الحمولة (KG)</label>
                <input type="text" inputMode="decimal" name="load_kg" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.load_kg?.toString() || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد الأشخاص</label>
                <input type="number" name="number_of_persons" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.number_of_persons || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد المداخل</label>
                <input type="number" name="number_of_entrances" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.number_of_entrances || ''} />
              </div>
              <div className="space-y-2 md:col-span-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أسماء الوقفات</label>
                <input type="text" name="stop_names" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.stop_names || ''} />
              </div>
            </div>
          </div>

          {/* البئر والكابينة */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">تفاصيل البئر والكابينة</h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">خامة البئر</label>
                <input type="text" name="shaft_material" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.shaft_material || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">المقاس الداخلي للبئر</label>
                <input type="text" name="shaft_internal_size" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.shaft_internal_size || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">إطار الكابينة (Car Frame)</label>
                <input type="text" name="car_frame" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.car_frame || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">التشطيب الداخلي</label>
                <input type="text" name="car_finish" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.car_finish || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أبعاد الكابينة</label>
                <input type="text" name="inside_car_dimensions" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.inside_car_dimensions || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الأرضية (Floor)</label>
                <input type="text" name="floor" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.floor || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">السقف (Roof)</label>
                <input type="text" name="roof" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.roof || ''} />
              </div>
            </div>
          </div>

          {/* الأبواب */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">الأبواب</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">طريقة تشغيل الأبواب</label>
                <input type="text" name="door_operation" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.door_operation || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أبعاد الأبواب</label>
                <input type="text" name="door_dimensions" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.door_dimensions || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الباب الداخلي</label>
                <input type="text" name="inner_door" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.inner_door || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الباب الخارجي الرئيسي</label>
                <input type="text" name="landing_door_main" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.landing_door_main || ''} />
              </div>
              <div className="space-y-2 md:col-span-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أبواب خارجية أخرى</label>
                <input type="text" name="landing_door_other" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.landing_door_other || ''} />
              </div>
            </div>
          </div>

          {/* أنظمة أخرى */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">أنظمة التوجيه والتحكم</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">دليل الحركة (Guide Rail)</label>
                <input type="text" name="guide_rail" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.guide_rail || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">دليل حركة الثقل (Counterweight)</label>
                <input type="text" name="counterweight_guide_rails" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.counterweight_guide_rails || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">حبال الجر (Traction Ropes)</label>
                <input type="text" name="traction_ropes" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.traction_ropes || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">كيبل المرن (Traveling Cable)</label>
                <input type="text" name="traveling_cable" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.traveling_cable || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">طريقة التشغيل (Operation Method)</label>
                <input type="text" name="operation_method" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.operation_method || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">التيار الكهربائي</label>
                <input type="text" name="electrical_current" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.electrical_current || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">لوحة الطلب الداخلي (COP)</label>
                <input type="text" name="cop" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.cop || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">إضاءة الطوارئ</label>
                <input type="text" name="emergency_light" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.emergency_light || ''} />
              </div>
            </div>
          </div>

          {/* التسعير والتفاصيل الإضافية */}
          <div className="border border-[var(--color-gold)] p-5 rounded-lg bg-[#f8f9fa] shadow-sm">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
              <i className="fas fa-money-bill-wave text-[var(--color-gold)]"></i> التسعير والتفاصيل الإضافية
            </h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">السعر الإجمالي</label>
                <input type="text" inputMode="decimal" name="total_price" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)] font-bold text-lg" defaultValue={quote.total_price?.toString() || ''} />
              </div>
              <div className="space-y-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الخصم (Discount)</label>
                <input type="text" inputMode="decimal" name="discount_amount" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-red-500 font-medium" defaultValue={quote.discount_amount?.toString() || ''} />
              </div>
              <div className="space-y-2 md:col-span-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">تفاصيل السعر</label>
                <textarea name="price_details" rows={3} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.price_details || ''}></textarea>
              </div>
              <div className="space-y-2 md:col-span-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">التوريد والتركيب</label>
                <textarea name="supply_and_install" rows={2} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.supply_and_install || ''}></textarea>
              </div>
              <div className="space-y-2 md:col-span-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الضمان والصيانة المجانية</label>
                <textarea name="warranty_and_free_maintenance" rows={2} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.warranty_and_free_maintenance || ''}></textarea>
              </div>
              <div className="space-y-2 md:col-span-2">
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الأعمال التحضيرية (Preparatory Works)</label>
                <textarea name="preparatory_works" rows={2} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={quote.preparatory_works || ''}></textarea>
              </div>
            </div>
          </div>

          <div className="pt-6 border-t border-[var(--color-border)] flex justify-end gap-3 sticky bottom-4 bg-white p-4 rounded-lg shadow-lg border">
            <Link href={`/quotes/${quoteId}`} className="btn-gray px-6 py-2">
              إلغاء
            </Link>
            <button type="submit" className="btn-gold px-8 py-2 text-lg shadow-md hover:shadow-lg transition-shadow">
              <i className="fas fa-save"></i>
              حفظ التعديلات
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
