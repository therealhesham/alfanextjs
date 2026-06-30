'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { getClientByPhone, createQuote } from '@/app/actions/quoteActions';

export default function NewQuotePage() {
  const router = useRouter();
  
  // Client selection state
  const [phone, setPhone] = useState('');
  const [clientFound, setClientFound] = useState<boolean | null>(null);
  const [clientName, setClientName] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const [phoneError, setPhoneError] = useState('');

  // Form submission state
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Phone input handler mimicking login page
  const handlePhoneChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    let val = e.target.value.replace(/[^0-9]/g, '');
    if (val.startsWith('0')) {
      val = val.substring(1);
    }
    if (val.length > 0 && val[0] !== '5') {
      setPhoneError('رقم الجوال يجب أن يبدأ بالرقم 5');
      val = '';
    } else {
      setPhoneError('');
    }
    if (val.length > 9) val = val.substring(0, 9);
    setPhone(val);
    
    // Reset client state when phone changes
    setClientFound(null);
  };

  const handleSearchClient = async () => {
    if (!phone) {
      setPhoneError('يرجى إدخال رقم الجوال');
      return;
    }
    if (!/^5[0-9]{8}$/.test(phone)) {
      setPhoneError('رقم الجوال غير صحيح');
      return;
    }

    setIsSearching(true);
    setPhoneError('');
    
    try {
      const result = await getClientByPhone(phone);
      if (result.success && result.user) {
        setClientFound(true);
        setClientName(result.user.name || 'عميل بدون اسم');
      } else {
        setClientFound(false);
        setClientName('');
      }
    } catch (error) {
      setPhoneError('حدث خطأ أثناء البحث عن العميل');
    } finally {
      setIsSearching(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (clientFound === null) {
      alert('الرجاء البحث عن العميل وتأكيده أولاً');
      return;
    }
    if (clientFound === false && !clientName.trim()) {
      alert('الرجاء إدخال اسم العميل الجديد');
      return;
    }

    setIsSubmitting(true);
    
    const formData = new FormData(e.currentTarget);
    const dataObj = Object.fromEntries(formData.entries());
    
    try {
      const result = await createQuote(dataObj, phone, clientName);
      if (result.success && result.quoteId) {
        alert('تم إنشاء عرض السعر بنجاح');
        router.push(`/quotes/${result.quoteId}`);
      } else {
        alert(result.error || 'حدث خطأ أثناء الإنشاء');
      }
    } catch (error) {
      alert('حدث خطأ أثناء الاتصال بالخادم');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="max-w-5xl mx-auto p-3 md:p-6 min-h-screen bg-[var(--color-light-gray)]" dir="rtl">
      <div className="card mb-6">
        <div className="flex flex-col md:flex-row justify-between items-center mb-6 border-b border-[var(--color-border)] pb-4 gap-4">
          <h1 className="text-xl md:text-2xl font-bold text-[var(--color-dark-gray)] flex items-center gap-3">
            <i className="fas fa-plus-circle text-[var(--color-gold)] text-lg md:text-xl"></i>
            إنشاء عرض سعر جديد
          </h1>
          <div className="flex gap-3 w-full md:w-auto">
            <Link href="/" className="btn-gray w-full md:w-auto text-center justify-center">
              <i className="fas fa-arrow-right"></i>
              رجوع للرئيسية
            </Link>
          </div>
        </div>

        <form className="space-y-8" onSubmit={handleSubmit}>
          
          {/* Client Selection */}
          <div className="border border-[var(--color-border)] p-5 rounded-lg bg-[#f8f9fa]">
            <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
              <i className="fas fa-user text-[var(--color-gold)]"></i> بيانات العميل (رقم الجوال أولاً)
            </h3>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
              <div>
                <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">رقم جوال العميل</label>
                <div className="flex items-center flex-row-reverse border border-[var(--color-border)] rounded-md bg-white overflow-hidden shadow-sm focus-within:ring-2 focus-within:ring-[var(--color-gold-light)] focus-within:border-[var(--color-gold)]">
                  <input 
                    type="tel" 
                    className="flex-1 p-2.5 outline-none text-[var(--color-dark-gray)] text-left" 
                    placeholder="5xxxxxxxx"
                    maxLength={9}
                    inputMode="numeric"
                    pattern="[0-9]*"
                    value={phone}
                    onChange={handlePhoneChange}
                    dir="ltr"
                  />
                  <span className="bg-gray-100 px-3 py-2.5 text-gray-600 font-medium border-l border-[var(--color-border)] text-sm" dir="ltr">
                    +966
                  </span>
                </div>
                {phoneError && <p className="text-red-500 text-xs mt-1">{phoneError}</p>}
              </div>
              
              <div>
                <button 
                  type="button" 
                  onClick={handleSearchClient}
                  disabled={isSearching || !phone || phone.length < 9}
                  className="btn-gray w-full py-2.5"
                >
                  {isSearching ? <i className="fas fa-spinner fa-spin"></i> : <i className="fas fa-search"></i>}
                  تأكيد / بحث
                </button>
              </div>
            </div>

            {/* Client Search Result */}
            {clientFound === true && (
              <div className="mt-4 bg-green-50 border border-green-200 text-green-800 p-3 rounded-md flex items-center gap-2">
                <i className="fas fa-check-circle text-green-600"></i>
                <span>العميل مسجل مسبقاً: <strong>{clientName}</strong></span>
              </div>
            )}

            {clientFound === false && (
              <div className="mt-4 bg-blue-50 border border-blue-200 p-4 rounded-md">
                <div className="text-blue-800 mb-3 flex items-center gap-2">
                  <i className="fas fa-info-circle text-blue-600"></i>
                  <span>العميل غير مسجل. سيتم إضافته كعميل جديد، يرجى إدخال اسمه.</span>
                </div>
                <div>
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">اسم العميل الجديد</label>
                  <input 
                    type="text" 
                    value={clientName}
                    onChange={(e) => setClientName(e.target.value)}
                    className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" 
                    placeholder="أدخل اسم العميل ثلاثي"
                    required
                  />
                </div>
              </div>
            )}
          </div>

          {/* المواصفات الأساسية */}
          <div className={`transition-opacity duration-300 ${clientFound !== null ? 'opacity-100' : 'opacity-50 pointer-events-none'}`}>
            <div className="border border-[var(--color-border)] p-5 rounded-lg">
              <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">المواصفات الأساسية والماكينة</h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد المصاعد</label>
                  <input type="number" name="number_of_elevators" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" defaultValue={1} />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">نوع الماكينة</label>
                  <input type="text" name="machine_type" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">مكان الماكينة</label>
                  <input type="text" name="machine_position" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد الوقفات</label>
                  <input type="number" name="number_of_stops" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الحمولة (KG)</label>
                  <input type="text" inputMode="decimal" name="load_kg" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد الأشخاص</label>
                  <input type="number" name="number_of_persons" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">عدد المداخل</label>
                  <input type="number" name="number_of_entrances" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أسماء الوقفات</label>
                  <input type="text" name="stop_names" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
              </div>
            </div>

            {/* البئر والكابينة */}
            <div className="border border-[var(--color-border)] p-5 rounded-lg mt-8">
              <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">تفاصيل البئر والكابينة</h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">خامة البئر</label>
                  <input type="text" name="shaft_material" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">المقاس الداخلي للبئر</label>
                  <input type="text" name="shaft_internal_size" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">إطار الكابينة (Car Frame)</label>
                  <input type="text" name="car_frame" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">التشطيب الداخلي</label>
                  <input type="text" name="car_finish" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أبعاد الكابينة</label>
                  <input type="text" name="inside_car_dimensions" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الأرضية (Floor)</label>
                  <input type="text" name="floor" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">السقف (Roof)</label>
                  <input type="text" name="roof" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
              </div>
            </div>

            {/* الأبواب */}
            <div className="border border-[var(--color-border)] p-5 rounded-lg mt-8">
              <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">الأبواب</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">طريقة تشغيل الأبواب</label>
                  <input type="text" name="door_operation" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أبعاد الأبواب</label>
                  <input type="text" name="door_dimensions" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الباب الداخلي</label>
                  <input type="text" name="inner_door" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الباب الخارجي الرئيسي</label>
                  <input type="text" name="landing_door_main" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">أبواب خارجية أخرى</label>
                  <input type="text" name="landing_door_other" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
              </div>
            </div>

            {/* أنظمة أخرى */}
            <div className="border border-[var(--color-border)] p-5 rounded-lg mt-8">
              <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">أنظمة التوجيه والتحكم</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">دليل الحركة (Guide Rail)</label>
                  <input type="text" name="guide_rail" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">دليل حركة الثقل (Counterweight)</label>
                  <input type="text" name="counterweight_guide_rails" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">حبال الجر (Traction Ropes)</label>
                  <input type="text" name="traction_ropes" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">كيبل المرن (Traveling Cable)</label>
                  <input type="text" name="traveling_cable" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">طريقة التشغيل (Operation Method)</label>
                  <input type="text" name="operation_method" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">التيار الكهربائي</label>
                  <input type="text" name="electrical_current" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">لوحة الطلب الداخلي (COP)</label>
                  <input type="text" name="cop" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">إضاءة الطوارئ</label>
                  <input type="text" name="emergency_light" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]" />
                </div>
              </div>
            </div>

            {/* التسعير والتفاصيل الإضافية */}
            <div className="border border-[var(--color-gold)] p-5 rounded-lg bg-[#f8f9fa] shadow-sm mt-8">
              <h3 className="font-bold text-lg mb-4 text-[var(--color-dark-gray)] border-b border-[var(--color-border)] pb-2 flex items-center gap-2">
                <i className="fas fa-money-bill-wave text-[var(--color-gold)]"></i> التسعير والتفاصيل الإضافية
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">السعر الإجمالي</label>
                  <input type="text" inputMode="decimal" name="total_price" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)] font-bold text-lg" />
                </div>
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الخصم (Discount)</label>
                  <input type="text" inputMode="decimal" name="discount_amount" className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-red-500 font-medium" />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">تفاصيل السعر</label>
                  <textarea name="price_details" rows={3} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]"></textarea>
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">التوريد والتركيب</label>
                  <textarea name="supply_and_install" rows={2} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]"></textarea>
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الضمان والصيانة المجانية</label>
                  <textarea name="warranty_and_free_maintenance" rows={2} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]"></textarea>
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="block text-sm font-medium text-[var(--color-medium-gray)] mb-1">الأعمال التحضيرية (Preparatory Works)</label>
                  <textarea name="preparatory_works" rows={2} className="w-full border border-[var(--color-border)] rounded-md shadow-sm focus:ring-[var(--color-gold-light)] focus:border-[var(--color-gold)] p-2.5 bg-white text-[var(--color-dark-gray)]"></textarea>
                </div>
              </div>
            </div>

            <div className="pt-6 border-t border-[var(--color-border)] flex justify-end gap-3 sticky bottom-4 bg-white p-4 rounded-lg shadow-lg border mt-8">
              <Link href="/" className="btn-gray px-6 py-2">
                إلغاء
              </Link>
              <button 
                type="submit" 
                disabled={isSubmitting || clientFound === null || (clientFound === false && !clientName.trim())}
                className="btn-gold px-8 py-2 text-lg shadow-md hover:shadow-lg transition-shadow"
              >
                {isSubmitting ? <i className="fas fa-spinner fa-spin ml-2"></i> : <i className="fas fa-save ml-2"></i>}
                حفظ وإصدار العرض
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  );
}
