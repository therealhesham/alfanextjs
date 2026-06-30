import React from 'react';
import { prisma } from '@/lib/prisma';
import Link from 'next/link';

export default async function ViewQuotePage({ params }: { params: Promise<{ id: string }> }) {
  const resolvedParams = await params;
  const quoteId = parseInt(resolvedParams.id, 10);

  if (isNaN(quoteId)) {
    return <div className="p-8 text-center text-red-500">رقم عرض سعر غير صحيح</div>;
  }

  const quote = await prisma.elevator_quotes.findUnique({
    where: { id: BigInt(quoteId) },
    include: {
      users_elevator_quotes_client_user_idTousers: true,
      users_elevator_quotes_created_by_user_idTousers: true,
      brands: true,
      quote_statuses: true,
    },
  });

  if (!quote) {
    return <div className="p-8 text-center">لم يتم العثور على عرض السعر</div>;
  }

  return (
    <div className="max-w-5xl mx-auto p-3 md:p-6 min-h-screen bg-[var(--color-light-gray)]" dir="rtl">
      <div className="card mb-6">
        <div className="flex flex-col md:flex-row justify-between items-center mb-6 border-b border-[var(--color-border)] pb-4 gap-4">
          <h1 className="text-xl md:text-2xl font-bold text-[var(--color-dark-gray)] flex items-center gap-3">
            <i className="fas fa-file-invoice-dollar text-[var(--color-gold)] text-lg md:text-xl"></i>
            تفاصيل عرض السعر #{quote.quote_number?.toString() || quote.id.toString()}
          </h1>
          <div className="flex gap-3 w-full md:w-auto">
            <Link href={`/quotes/${quoteId}/edit`} className="btn-gold w-full md:w-auto text-center justify-center">
              <i className="fas fa-edit"></i>
              تعديل
            </Link>
            <Link href="/" className="btn-gray w-full md:w-auto text-center justify-center">
              <i className="fas fa-arrow-right"></i>
              رجوع
            </Link>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* بيانات العميل */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg bg-[#f8f9fa]">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
              <i className="fas fa-user text-[var(--color-gold)]"></i> بيانات العميل
            </h3>
            <div className="space-y-3">
              <div className="flex justify-between items-center">
                <span className="text-[var(--color-medium-gray)] text-sm">الاسم:</span>
                <span className="font-medium text-[var(--color-dark-gray)]">{quote.users_elevator_quotes_client_user_idTousers?.name || 'غير محدد'}</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-[var(--color-medium-gray)] text-sm">الهاتف:</span>
                <span className="font-medium text-[var(--color-dark-gray)]" dir="ltr">{quote.users_elevator_quotes_client_user_idTousers?.phone_number || 'غير محدد'}</span>
              </div>
            </div>
          </div>

          {/* بيانات العرض الأساسية */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg bg-[#f8f9fa]">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
              <i className="fas fa-file-alt text-[var(--color-gold)]"></i> بيانات العرض
            </h3>
            <div className="space-y-3">
              <div className="flex justify-between items-center">
                <span className="text-[var(--color-medium-gray)] text-sm">التاريخ:</span>
                <span className="font-medium text-[var(--color-dark-gray)]">
                  {quote.created_at ? new Intl.DateTimeFormat('ar-SA', { dateStyle: 'medium' }).format(quote.created_at) : ''}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-[var(--color-medium-gray)] text-sm">البراند:</span>
                <span className="font-medium text-[var(--color-dark-gray)]">{quote.brands?.name || 'غير محدد'}</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-[var(--color-medium-gray)] text-sm">الحالة:</span>
                <span className="font-medium bg-[var(--color-gold-light)] text-[var(--color-gold)] px-2.5 py-1 rounded-md text-sm border border-[var(--color-gold)]">
                  {quote.quote_statuses?.name || quote.status_enum || 'غير محدد'}
                </span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-[var(--color-medium-gray)] text-sm">منشئ العرض:</span>
                <span className="font-medium text-[var(--color-dark-gray)]">{quote.users_elevator_quotes_created_by_user_idTousers?.name || 'غير محدد'}</span>
              </div>
            </div>
          </div>
        </div>

        {/* المواصفات الفنية */}
        <div className="mt-8 border border-[var(--color-border)] p-5 rounded-lg">
          <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
            <i className="fas fa-cogs text-[var(--color-gold)]"></i> المواصفات الفنية (Technical Specs)
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <div className="bg-[#f8f9fa] p-3 rounded-lg border border-[var(--color-border)]">
              <span className="block text-sm text-[var(--color-medium-gray)] mb-1">عدد المصاعد</span>
              <span className="font-medium text-[var(--color-dark-gray)]">{quote.number_of_elevators}</span>
            </div>
            <div className="bg-[#f8f9fa] p-3 rounded-lg border border-[var(--color-border)]">
              <span className="block text-sm text-[var(--color-medium-gray)] mb-1">عدد الوقفات</span>
              <span className="font-medium text-[var(--color-dark-gray)]">{quote.number_of_stops || '-'}</span>
            </div>
            <div className="bg-[#f8f9fa] p-3 rounded-lg border border-[var(--color-border)]">
              <span className="block text-sm text-[var(--color-medium-gray)] mb-1">الحمولة (KG)</span>
              <span className="font-medium text-[var(--color-dark-gray)]">{quote.load_kg?.toString() || '-'}</span>
            </div>
            <div className="bg-[#f8f9fa] p-3 rounded-lg border border-[var(--color-border)]">
              <span className="block text-sm text-[var(--color-medium-gray)] mb-1">نوع الماكينة</span>
              <span className="font-medium text-[var(--color-dark-gray)]">{quote.machine_type || '-'}</span>
            </div>
            <div className="bg-[#f8f9fa] p-3 rounded-lg border border-[var(--color-border)]">
              <span className="block text-sm text-[var(--color-medium-gray)] mb-1">مكان الماكينة</span>
              <span className="font-medium text-[var(--color-dark-gray)]">{quote.machine_position || '-'}</span>
            </div>
            <div className="bg-[#f8f9fa] p-3 rounded-lg border border-[var(--color-border)]">
              <span className="block text-sm text-[var(--color-medium-gray)] mb-1">التشطيب الداخلي للكابينة</span>
              <span className="font-medium text-[var(--color-dark-gray)]">{quote.car_finish || '-'}</span>
            </div>
          </div>
        </div>

        {/* التسعير */}
        <div className="mt-8 bg-[#f8f9fa] p-6 rounded-lg border-2 border-[var(--color-gold)] relative overflow-hidden">
          <div className="absolute top-0 right-0 w-16 h-16 bg-[var(--color-gold)] opacity-10 rounded-bl-full"></div>
          <h3 className="font-bold text-xl mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-3 flex items-center gap-2">
            <i className="fas fa-money-bill-wave text-[var(--color-gold)]"></i> تفاصيل التسعير
          </h3>
          <div className="flex justify-between items-center text-lg mt-4">
            <span className="text-[var(--color-medium-gray)] font-medium">السعر الإجمالي:</span>
            <span className="font-bold text-3xl text-[var(--color-dark-gray)]">{quote.total_price?.toString() || '0'} <span className="text-lg text-[var(--color-gold)]">ر.س</span></span>
          </div>
          {quote.discount_amount && Number(quote.discount_amount) > 0 && (
            <div className="flex justify-between items-center mt-3 text-md border-t border-[var(--color-border)] pt-3">
              <span className="text-[var(--color-medium-gray)] font-medium">الخصم:</span>
              <span className="font-semibold text-red-500 bg-red-50 px-3 py-1 rounded-md">-{quote.discount_amount.toString()} ر.س</span>
            </div>
          )}
        </div>

      </div>
    </div>
  );
}
