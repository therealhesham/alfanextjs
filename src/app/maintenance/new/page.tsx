'use client';

import React, { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { 
  Search, 
  UserPlus, 
  Building, 
  CheckCircle, 
  ArrowRight, 
  ArrowLeft, 
  MapPin, 
  Calendar, 
  DollarSign, 
  Loader2, 
  ShieldAlert,
  User,
  Phone,
  Briefcase,
  AlertTriangle
} from 'lucide-react';
import { 
  searchClientByPhone, 
  createClient, 
  getClientProjects, 
  createProject, 
  getPricingPlans, 
  getDistricts, 
  getProjectStatus,
  createMaintenanceContract
} from '@/app/actions/maintenanceActions';

interface ClientData {
  id: string;
  name: string;
  phone: string;
  gender: string;
  prefix: string;
  suffix: string;
  idNumber: string;
  address: string;
}

interface ProjectData {
  id: string;
  name: string;
  city: string;
  type: string;
  address: string;
  locationUrl: string;
}

interface PlanData {
  id: string;
  name: string;
  cost: number;
}

interface DistrictData {
  id: string;
  name: string;
  groupId: string | null;
  groupName: string | null;
}

export default function NewContractPage() {
  const router = useRouter();
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error' | 'warning', text: string } | null>(null);

  // Data arrays from database
  const [plans, setPlans] = useState<PlanData[]>([]);
  const [districts, setDistricts] = useState<DistrictData[]>([]);

  // Autocomplete state
  const [districtSearch, setDistrictSearch] = useState('');
  const [showDistrictDropdown, setShowDistrictDropdown] = useState(false);

  // Wizard state
  const [phoneSearch, setPhoneSearch] = useState('');
  const [client, setClient] = useState<ClientData | null>(null);
  const [projects, setProjects] = useState<ProjectData[]>([]);
  const [selectedProject, setSelectedProject] = useState<ProjectData | null>(null);

  // New Client Form state
  const [newClient, setNewClient] = useState({
    name: '',
    phone: '',
    gender: 'ذكر',
    prefix: 'السيد',
    suffix: 'المحترم',
    idNumber: '',
    address: ''
  });
  const [showAddClientForm, setShowAddClientForm] = useState(false);

  // New Project Form state
  const [showAddProjectModal, setShowAddProjectModal] = useState(false);
  const [newProject, setNewProject] = useState({
    name: '',
    city: 'المدينة المنورة',
    type: 'سكني',
    address: '',
    locationUrl: ''
  });

  // Step 3 Form state
  const [contractStartMonth, setContractStartMonth] = useState('');
  const [contractDuration, setContractDuration] = useState(1);
  const [contractType, setContractType] = useState('');
  const [selectedDistrictId, setSelectedDistrictId] = useState('');
  const [isFullyPaid, setIsFullyPaid] = useState(false);
  const [isGuarantee, setIsGuarantee] = useState(false);

  // Guarantee Modal/Confirm Prompt state
  const [showGuaranteeModal, setShowGuaranteeModal] = useState(false);
  const [guaranteeChecking, setGuaranteeChecking] = useState(false);

  // Fetch Pricing Plans and Districts once
  useEffect(() => {
    async function loadData() {
      const planList = await getPricingPlans();
      const distList = await getDistricts();
      setPlans(planList);
      setDistricts(distList);
      
      // Default Start Month (next month)
      const today = new Date();
      today.setMonth(today.getMonth() + 1);
      const yyyy = today.getFullYear();
      const mm = String(today.getMonth() + 1).padStart(2, '0');
      setContractStartMonth(`${yyyy}-${mm}-01`);
    }
    loadData();
  }, []);

  // Format end date dynamically
  const computedEndDate = () => {
    if (!contractStartMonth) return '-';
    try {
      const start = new Date(contractStartMonth);
      const end = new Date(start);
      end.setFullYear(end.getFullYear() + contractDuration);
      end.setDate(end.getDate() - 1);
      
      const months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
      return `${end.getDate()} ${months[end.getMonth()]} ${end.getFullYear()}`;
    } catch {
      return '-';
    }
  };

  // Step 1: Search Client
  const handleClientSearch = async () => {
    if (!phoneSearch.trim()) return;
    setLoading(true);
    setMessage(null);
    try {
      const res = await searchClientByPhone(phoneSearch);
      if (res.success && res.client) {
        setClient(res.client);
        // Fetch projects
        const projList = await getClientProjects(res.client.id);
        setProjects(projList);
        setStep(2);
      } else {
        // Client not found, prep new client form with this phone
        setNewClient({ ...newClient, phone: phoneSearch });
        setShowAddClientForm(true);
        setMessage({ type: 'warning', text: 'لم يتم العثور على عميل بهذا الرقم، يرجى ملء البيانات لإضافة عميل جديد' });
      }
    } catch {
      setMessage({ type: 'error', text: 'فشل الاتصال بقاعدة البيانات' });
    } finally {
      setLoading(false);
    }
  };

  // Step 1: Create New Client
  const handleCreateClient = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newClient.name.trim() || !newClient.phone.trim()) {
      setMessage({ type: 'error', text: 'يرجى ملء الحقول الإجبارية (الاسم ورقم الجوال)' });
      return;
    }

    setLoading(true);
    setMessage(null);
    try {
      const res = await createClient(newClient);
      if (res.success && res.client) {
        setClient({
          id: res.client.id,
          name: res.client.name,
          phone: res.client.phone,
          gender: newClient.gender,
          prefix: newClient.prefix,
          suffix: newClient.suffix,
          idNumber: newClient.idNumber,
          address: newClient.address
        });
        setProjects([]);
        setStep(2);
        setShowAddClientForm(false);
        setMessage({ type: 'success', text: 'تم تسجيل العميل بنجاح!' });
      } else {
        setMessage({ type: 'error', text: res.error || 'فشل حفظ العميل' });
      }
    } catch {
      setMessage({ type: 'error', text: 'فشل الاتصال بقاعدة البيانات' });
    } finally {
      setLoading(false);
    }
  };

  // Step 2: Select Project & Check Stage
  const handleSelectProject = async (proj: ProjectData) => {
    setSelectedProject(proj);
    setGuaranteeChecking(true);
    try {
      const statusCheck = await getProjectStatus(proj.id);
      if (statusCheck.isPhase4) {
        // Show Guarantee confirmation popup
        setShowGuaranteeModal(true);
      } else {
        setIsGuarantee(false);
        setStep(3);
      }
    } catch (e) {
      console.error(e);
      setIsGuarantee(false);
      setStep(3);
    } finally {
      setGuaranteeChecking(false);
    }
  };

  const handleGuaranteeModalChoice = (isG: boolean) => {
    setIsGuarantee(isG);
    setShowGuaranteeModal(false);
    setStep(3);
  };

  // Step 2: Create New Project
  const handleCreateProject = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!client) return;
    if (!newProject.name.trim() || !newProject.locationUrl.trim()) {
      setMessage({ type: 'error', text: 'اسم المشروع ورابط الموقع حقول مطلوبة' });
      return;
    }

    setLoading(true);
    try {
      const res = await createProject({
        ...newProject,
        ownerUserIdStr: client.id
      });

      if (res.success && res.project) {
        const newProj = res.project;
        setProjects([...projects, newProj]);
        setShowAddProjectModal(false);
        setSelectedProject(newProj);
        setNewProject({
          name: '',
          city: 'المدينة المنورة',
          type: 'سكني',
          address: '',
          locationUrl: ''
        });
        // Check stage for the newly created project (typically not Phase 4 yet, but to be consistent)
        setStep(3);
        setIsGuarantee(false);
        setMessage({ type: 'success', text: 'تمت إضافة المشروع واختياره بنجاح!' });
      } else {
        setMessage({ type: 'error', text: res.error || 'فشل حفظ المشروع' });
      }
    } catch {
      setMessage({ type: 'error', text: 'فشل الاتصال بقاعدة البيانات' });
    } finally {
      setLoading(false);
    }
  };

  // Step 3: Create Contract
  const handleCreateContractSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!client || !selectedProject) return;
    if (!selectedDistrictId) {
      setMessage({ type: 'error', text: 'يرجى اختيار الحي' });
      return;
    }
    if (!contractType) {
      setMessage({ type: 'error', text: 'يرجى اختيار نوع العقد' });
      return;
    }

    setLoading(true);
    setMessage(null);

    // Calculate dates
    const start = new Date(contractStartMonth);
    const end = new Date(start);
    end.setFullYear(end.getFullYear() + contractDuration);
    end.setDate(end.getDate() - 1);

    const data = {
      clientIdStr: client.id,
      projectIdStr: selectedProject.id,
      startDateStr: contractStartMonth,
      endDateStr: end.toISOString().split('T')[0],
      pricingPlanIdStr: contractType,
      districtIdStr: selectedDistrictId,
      isFullyPaid,
      isGuarantee
    };

    try {
      const res = await createMaintenanceContract(data);
      if (res.success) {
        setMessage({ 
          type: 'success', 
          text: `تم إنشاء عقد الصيانة بنجاح! رقم الباركود: #${res.barcode}. عدد الكروت المنشأة: ${res.cardsCreated}` 
        });
        setTimeout(() => {
          router.push('/maintenance');
        }, 3000);
      } else {
        setMessage({ type: 'error', text: res.error || 'فشل حفظ العقد' });
      }
    } catch {
      setMessage({ type: 'error', text: 'فشل في الاتصال بقاعدة البيانات' });
    } finally {
      setLoading(false);
    }
  };

  // District Autocomplete search filter
  const filteredDistricts = districts.filter(d => 
    d.name.toLowerCase().includes(districtSearch.toLowerCase())
  );

  return (
    <div className="p-4 md:p-6 lg:p-8 max-w-4xl mx-auto space-y-6" dir="rtl">
      
      {/* Header */}
      <div className="flex justify-between items-center bg-white p-5 rounded-2xl shadow-sm border border-slate-200">
        <div>
          <h1 className="text-2xl font-bold text-[#1e293b] flex items-center gap-2">
            <span className="w-2.5 h-6 bg-[#977e2b] rounded-full inline-block"></span>
            إضافة عقد صيانة جديد
          </h1>
          <p className="text-sm text-slate-500 mt-1">اتبع خطوات المعالج لربط العميل والإنشاء</p>
        </div>
        <button 
          onClick={() => router.push('/maintenance')}
          className="text-sm text-slate-500 hover:text-[#977e2b] font-semibold border border-slate-200 hover:border-[#977e2b] px-4 py-2 rounded-full transition-all"
        >
          العودة للعقود
        </button>
      </div>

      {/* Stepper Indicator */}
      <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div className="flex justify-between items-center relative">
          <div className="absolute right-0 left-0 top-5 h-0.5 bg-slate-200 -z-10"></div>
          <div 
            className="absolute right-0 h-0.5 bg-[#977e2b] transition-all duration-300 -z-10" 
            style={{ width: `${(step - 1) * 50}%`, left: 'auto' }}
          ></div>

          <div className="flex flex-col items-center flex-1">
            <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm border-2 transition-all ${step >= 1 ? 'bg-[#977e2b] border-[#977e2b] text-white' : 'bg-white border-slate-200 text-slate-400'}`}>
              {step > 1 ? <CheckCircle size={18} /> : '1'}
            </div>
            <span className={`text-xs font-semibold mt-2 ${step >= 1 ? 'text-[#977e2b]' : 'text-slate-400'}`}>البحث عن العميل</span>
          </div>

          <div className="flex flex-col items-center flex-1">
            <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm border-2 transition-all ${step >= 2 ? 'bg-[#977e2b] border-[#977e2b] text-white' : 'bg-white border-slate-200 text-slate-400'}`}>
              {step > 2 ? <CheckCircle size={18} /> : '2'}
            </div>
            <span className={`text-xs font-semibold mt-2 ${step >= 2 ? 'text-[#977e2b]' : 'text-slate-400'}`}>اختيار المشروع</span>
          </div>

          <div className="flex flex-col items-center flex-1">
            <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm border-2 transition-all ${step >= 3 ? 'bg-[#977e2b] border-[#977e2b] text-white' : 'bg-white border-slate-200 text-slate-400'}`}>
              '3'
            </div>
            <span className={`text-xs font-semibold mt-2 ${step >= 3 ? 'text-[#977e2b]' : 'text-slate-400'}`}>بيانات العقد</span>
          </div>
        </div>
      </div>

      {/* Main Alert Message */}
      {message && (
        <div className={`p-4 rounded-xl flex items-center gap-3 border text-sm font-semibold animate-pulse ${
          message.type === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' :
          message.type === 'warning' ? 'bg-amber-50 text-amber-700 border-amber-200' :
          'bg-rose-50 text-rose-700 border-rose-200'
        }`}>
          {message.type === 'error' ? <ShieldAlert size={20} /> : <CheckCircle size={20} />}
          <span>{message.text}</span>
        </div>
      )}

      {/* STEP 1: Search / Add Client */}
      {step === 1 && (
        <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-6">
          <div className="border-b border-slate-100 pb-3">
            <h2 className="text-lg font-bold text-[#1e293b] flex items-center gap-2">
              <User size={20} className="text-[#977e2b]" />
              البحث عن العميل أو إضافة عميل جديد
            </h2>
          </div>

          {!showAddClientForm ? (
            <div className="max-w-md mx-auto space-y-4 py-4">
              <label className="text-sm font-bold text-slate-600 block">أدخل رقم الجوال للبحث التلقائي في النظام:</label>
              <div className="relative">
                <input 
                  type="text" 
                  placeholder="مثال: 0500000000" 
                  value={phoneSearch}
                  onChange={(e) => setPhoneSearch(e.target.value.replace(/[^0-9+]/g, ''))}
                  className="w-full pl-4 pr-12 py-3 rounded-xl border border-slate-200 focus:border-[#977e2b] focus:ring-2 focus:ring-[#977e2b]/10 outline-none transition-all font-semibold"
                />
                <Search size={20} className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400" />
              </div>
              <button
                onClick={handleClientSearch}
                disabled={loading || !phoneSearch.trim()}
                className="w-full bg-[#977e2b] hover:bg-[#b89635] text-white font-bold py-3 rounded-xl shadow-md transition-all flex items-center justify-center gap-2 disabled:opacity-50"
              >
                {loading ? <Loader2 size={20} className="animate-spin" /> : 'البحث والمتابعة'}
              </button>
            </div>
          ) : (
            <form onSubmit={handleCreateClient} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">اللقب</label>
                  <input 
                    type="text" 
                    value={newClient.prefix}
                    onChange={(e) => setNewClient({...newClient, prefix: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  />
                </div>
                <div className="md:col-span-2">
                  <label className="text-xs font-bold text-slate-600 block mb-1">الاسم الكامل <span className="text-rose-500">*</span></label>
                  <input 
                    type="text" 
                    required
                    value={newClient.name}
                    onChange={(e) => setNewClient({...newClient, name: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">رقم الجوال <span className="text-rose-500">*</span></label>
                  <input 
                    type="text" 
                    required
                    value={newClient.phone}
                    onChange={(e) => setNewClient({...newClient, phone: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  />
                </div>
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">الجنس</label>
                  <select 
                    value={newClient.gender}
                    onChange={(e) => setNewClient({...newClient, gender: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  >
                    <option value="ذكر">ذكر</option>
                    <option value="أنثى">أنثى</option>
                    <option value="شركة">شركة</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">الصفة</label>
                  <input 
                    type="text" 
                    value={newClient.suffix}
                    onChange={(e) => setNewClient({...newClient, suffix: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  />
                </div>
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">الهوية الوطنية / السجل التجاري</label>
                  <input 
                    type="text" 
                    value={newClient.idNumber}
                    onChange={(e) => setNewClient({...newClient, idNumber: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  />
                </div>
              </div>

              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">العنوان بالتفصيل</label>
                <input 
                  type="text" 
                  value={newClient.address}
                  onChange={(e) => setNewClient({...newClient, address: e.target.value})}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                />
              </div>

              <div className="flex gap-4 pt-4 border-t border-slate-100">
                <button
                  type="submit"
                  disabled={loading}
                  className="bg-[#977e2b] hover:bg-[#b89635] text-white font-bold py-2.5 px-6 rounded-xl transition-all flex items-center gap-2"
                >
                  {loading && <Loader2 size={16} className="animate-spin" />}
                  حفظ العميل والمتابعة
                </button>
                <button
                  type="button"
                  onClick={() => setShowAddClientForm(false)}
                  className="text-slate-500 hover:bg-slate-50 px-6 py-2.5 rounded-xl transition-all text-sm font-bold border border-slate-200"
                >
                  إلغاء والبحث مجدداً
                </button>
              </div>
            </form>
          )}
        </div>
      )}

      {/* STEP 2: Project Selection */}
      {step === 2 && client && (
        <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-6">
          <div className="border-b border-slate-100 pb-3 flex justify-between items-center">
            <h2 className="text-lg font-bold text-[#1e293b] flex items-center gap-2">
              <Building size={20} className="text-[#977e2b]" />
              اختيار المشروع للعميل: {client.name}
            </h2>
            <button
              onClick={() => setShowAddProjectModal(true)}
              className="text-xs bg-[#977e2b] hover:bg-[#b89635] text-white font-bold px-4 py-2 rounded-xl transition-all"
            >
              + إضافة مشروع جديد
            </button>
          </div>

          {/* Client Details Row */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 bg-slate-50 p-4 rounded-xl text-sm border border-slate-100">
            <div>
              <span className="text-slate-400 block mb-1">العميل:</span>
              <span className="font-bold text-slate-800">{client.name}</span>
            </div>
            <div>
              <span className="text-slate-400 block mb-1">رقم الهاتف:</span>
              <span className="font-bold text-slate-800">{client.phone}</span>
            </div>
            <div>
              <span className="text-slate-400 block mb-1">عدد المشاريع الحالية:</span>
              <span className="font-bold text-slate-800">{projects.length}</span>
            </div>
          </div>

          {/* Projects List */}
          {projects.length === 0 ? (
            <div className="text-center py-8 text-slate-500 text-sm">
              لا توجد مشاريع مسجلة لهذا العميل حالياً. يرجى إضافة مشروع بالضغط على الزر بالأعلى.
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {projects.map((proj) => (
                <div 
                  key={proj.id}
                  onClick={() => !guaranteeChecking && handleSelectProject(proj)}
                  className={`p-4 rounded-xl border border-slate-200 hover:border-[#977e2b] cursor-pointer transition-all flex flex-col justify-between h-36 hover:-translate-y-1 bg-white hover:shadow-md ${
                    selectedProject?.id === proj.id ? 'ring-2 ring-[#977e2b] border-[#977e2b]' : ''
                  }`}
                >
                  <div>
                    <h3 className="font-bold text-[#1e293b] text-base mb-1">{proj.name}</h3>
                    <div className="text-xs text-slate-500 space-y-0.5">
                      <div>المدينة: {proj.city}</div>
                      <div>النوع: {proj.type}</div>
                      <div className="truncate">العنوان: {proj.address}</div>
                    </div>
                  </div>
                  <div className="text-left mt-2">
                    <span className="text-xs font-bold text-[#977e2b] flex items-center justify-end gap-1">
                      {guaranteeChecking && selectedProject?.id === proj.id ? (
                        <Loader2 size={12} className="animate-spin" />
                      ) : (
                        <>
                          <span>اختيار المشروع</span>
                          <ArrowLeft size={12} />
                        </>
                      )}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}

          <div className="flex gap-4 pt-4 border-t border-slate-100">
            <button
              onClick={() => setStep(1)}
              className="text-slate-500 hover:bg-slate-50 px-6 py-2.5 rounded-xl transition-all text-sm font-bold border border-slate-200 flex items-center gap-2"
            >
              <ArrowRight size={16} />
              رجوع
            </button>
          </div>
        </div>
      )}

      {/* STEP 3: Contract Form */}
      {step === 3 && client && selectedProject && (
        <form onSubmit={handleCreateContractSubmit} className="space-y-6">
          
          {/* Client & Project Summary Card */}
          <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
            <h3 className="font-bold text-slate-800 text-sm border-b border-slate-100 pb-2">تفاصيل العميل والمشروع</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div className="bg-slate-50/50 p-3 rounded-xl">
                <span className="text-xs text-slate-400 block mb-1">العميل</span>
                <span className="font-bold text-slate-800">{client.prefix} {client.name} {client.suffix}</span>
              </div>
              <div className="bg-slate-50/50 p-3 rounded-xl">
                <span className="text-xs text-slate-400 block mb-1">المشروع</span>
                <span className="font-bold text-slate-800">{selectedProject.name} ({selectedProject.city})</span>
              </div>
            </div>
          </div>

          {/* Contract Details Form */}
          <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-6">
            <div className="border-b border-slate-100 pb-3">
              <h2 className="text-lg font-bold text-[#1e293b] flex items-center gap-2">
                <Calendar size={20} className="text-[#977e2b]" />
                بيانات عقد الصيانة
              </h2>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">تاريخ بداية العقد <span className="text-rose-500">*</span></label>
                <input 
                  type="date" 
                  required
                  value={contractStartMonth}
                  onChange={(e) => setContractStartMonth(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                />
              </div>

              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">مدة العقد (سنوات) <span className="text-rose-500">*</span></label>
                <div className="flex items-center border border-slate-200 rounded-xl overflow-hidden px-3 bg-white">
                  <input 
                    type="number" 
                    required
                    min={1}
                    value={contractDuration}
                    onChange={(e) => setContractDuration(parseInt(e.target.value) || 1)}
                    className="w-full py-2.5 outline-none text-sm font-bold border-none"
                  />
                  <span className="text-xs text-slate-400 whitespace-nowrap pl-2 border-r pr-2">تاريخ النهاية:</span>
                  <span className="text-xs font-bold text-emerald-600 whitespace-nowrap pl-1">{computedEndDate()}</span>
                </div>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">نوع العقد (باقة الأسعار) <span className="text-rose-500">*</span></label>
                <select
                  required
                  value={contractType}
                  onChange={(e) => setContractType(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold bg-white"
                >
                  <option value="">اختر باقة العقد...</option>
                  {plans.map(p => (
                    <option key={p.id} value={p.id}>{p.name} ({p.cost} ريال)</option>
                  ))}
                </select>
              </div>

              {/* District Autocomplete Search field */}
              <div className="relative">
                <label className="text-xs font-bold text-slate-600 block mb-1">الحي <span className="text-rose-500">*</span></label>
                <input 
                  type="text" 
                  placeholder="ابحث عن الحي..."
                  value={districtSearch}
                  onFocus={() => setShowDistrictDropdown(true)}
                  onChange={(e) => {
                    setDistrictSearch(e.target.value);
                    setShowDistrictDropdown(true);
                  }}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                />
                
                {showDistrictDropdown && (
                  <div className="absolute z-10 w-full bg-white border border-slate-200 rounded-xl shadow-lg mt-1 max-h-48 overflow-y-auto">
                    {filteredDistricts.length === 0 ? (
                      <div className="p-3 text-xs text-slate-400 text-center">لا توجد نتائج</div>
                    ) : (
                      filteredDistricts.map(d => (
                        <button
                          key={d.id}
                          type="button"
                          onClick={() => {
                            setSelectedDistrictId(d.id);
                            setDistrictSearch(d.name);
                            setShowDistrictDropdown(false);
                          }}
                          className={`w-full text-right px-4 py-2 text-xs hover:bg-[#977e2b]/10 hover:text-[#977e2b] transition-all font-semibold border-b border-slate-50 last:border-0 ${
                            selectedDistrictId === d.id ? 'bg-[#977e2b]/5 text-[#977e2b] font-bold' : 'text-slate-700'
                          }`}
                        >
                          {d.name} {d.groupName ? `(${d.groupName})` : ''}
                        </button>
                      ))
                    )}
                  </div>
                )}
              </div>
            </div>

            {/* Is paid option */}
            <div className="p-4 rounded-xl border border-slate-100 bg-slate-50/50 flex flex-col gap-2">
              <label className="flex items-center gap-3 cursor-pointer">
                <input 
                  type="checkbox"
                  checked={isFullyPaid}
                  onChange={(e) => setIsFullyPaid(e.target.checked)}
                  className="w-4.5 h-4.5 accent-[#977e2b] rounded cursor-pointer"
                />
                <span className="text-sm font-bold text-slate-700">هل تم سداد قيمة العقد بالكامل؟</span>
              </label>
              <p className="text-xs text-slate-400 font-semibold mr-7">
                عند تفعيل هذا الخيار، سيقوم النظام تلقائياً بتحديد تواريخ بدء ونهاية العقد بناءً على تاريخ اليوم، بالإضافة إلى إنشاء كروت الزيارات الشهرية فوراً.
              </p>
            </div>

            {/* Guarantee badge indicator */}
            {isGuarantee && (
              <div className="p-4 rounded-xl border border-blue-200 bg-blue-50 text-blue-800 flex items-center gap-3 text-xs font-bold shadow-sm">
                <ShieldAlert size={18} className="text-blue-600" />
                <span>تم تحديد هذا العقد كعقد ضمان بناءً على مرحلة المشروع (استلام المرحلة الرابعة).</span>
              </div>
            )}

            <div className="flex gap-4 pt-4 border-t border-slate-100">
              <button
                type="submit"
                disabled={loading}
                className="bg-[#977e2b] hover:bg-[#b89635] text-white font-bold py-3 px-8 rounded-xl transition-all flex items-center gap-2 shadow-md disabled:opacity-50"
              >
                {loading ? <Loader2 size={18} className="animate-spin" /> : <CheckCircle size={18} />}
                حفظ وإنشاء العقد
              </button>
              <button
                type="button"
                onClick={() => setStep(2)}
                className="text-slate-500 hover:bg-slate-50 px-6 py-3 rounded-xl transition-all text-sm font-bold border border-slate-200 flex items-center gap-2"
              >
                <ArrowRight size={16} />
                رجوع
              </button>
            </div>
          </div>
        </form>
      )}

      {/* NEW PROJECT MODAL */}
      {showAddProjectModal && client && (
        <div className="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
            <div className="p-4 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
              <h3 className="font-bold text-[#1e293b] text-base">إضافة مشروع جديد للعميل: {client.name}</h3>
              <button 
                onClick={() => setShowAddProjectModal(false)}
                className="text-gray-400 hover:text-gray-600 rounded-md p-1 transition-colors"
              >
                ✕
              </button>
            </div>
            <form onSubmit={handleCreateProject} className="p-5 space-y-4">
              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">اسم المشروع <span className="text-rose-500">*</span></label>
                <input 
                  type="text" 
                  required
                  placeholder="مثال: فيلا سكنية"
                  value={newProject.name}
                  onChange={(e) => setNewProject({...newProject, name: e.target.value})}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">المدينة</label>
                  <input 
                    type="text" 
                    required
                    value={newProject.city}
                    onChange={(e) => setNewProject({...newProject, city: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  />
                </div>
                <div>
                  <label className="text-xs font-bold text-slate-600 block mb-1">النوع</label>
                  <select
                    value={newProject.type}
                    onChange={(e) => setNewProject({...newProject, type: e.target.value})}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold bg-white"
                  >
                    <option value="سكني">سكني</option>
                    <option value="تجاري">تجاري</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">العنوان بالتفصيل</label>
                <input 
                  type="text" 
                  placeholder="الحي - الشارع"
                  value={newProject.address}
                  onChange={(e) => setNewProject({...newProject, address: e.target.value})}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                />
              </div>

              <div>
                <label className="text-xs font-bold text-slate-600 block mb-1">رابط الموقع (Google Maps) <span className="text-rose-500">*</span></label>
                <input 
                  type="url" 
                  required
                  placeholder="https://maps.app.goo.gl/..."
                  value={newProject.locationUrl}
                  onChange={(e) => setNewProject({...newProject, locationUrl: e.target.value})}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 outline-none text-sm font-semibold"
                  style={{ direction: 'ltr', textAlign: 'right' }}
                />
              </div>

              <div className="flex gap-3 pt-4 border-t border-gray-100">
                <button
                  type="submit"
                  disabled={loading}
                  className="flex-1 bg-[#977e2b] hover:bg-[#b89635] text-white py-2.5 rounded-xl font-bold transition-all flex items-center justify-center gap-2"
                >
                  {loading && <Loader2 size={16} className="animate-spin" />}
                  حفظ المشروع
                </button>
                <button 
                  type="button"
                  onClick={() => setShowAddProjectModal(false)}
                  className="flex-1 bg-gray-100 text-gray-700 py-2.5 rounded-xl font-bold hover:bg-gray-200 transition-colors"
                >
                  إلغاء
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* GUARANTEE POPUP MODAL */}
      {showGuaranteeModal && (
        <div className="fixed inset-0 bg-black/70 z-[100] flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden animate-in fade-in zoom-in duration-200">
            <div className="bg-amber-500 p-6 text-white text-center space-y-2">
              <div className="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center mx-auto text-white">
                <AlertTriangle size={32} />
              </div>
              <h3 className="text-lg font-bold">تنبيه مرحلة الضمان</h3>
              <p className="text-xs text-white/90">تعديل نوع العقد تلقائياً بناءً على حالة المشروع</p>
            </div>
            <div className="p-6 text-center space-y-4">
              <p className="text-sm font-bold text-slate-700">
                تم اكتشاف أن هذا المشروع في مرحلة الضمان (محضر استلام المرحلة الرابعة).
              </p>
              <p className="text-xs text-slate-500 font-semibold">
                هل ترغب في إضافة عقد الصيانة كعقد ضمان؟ (ستكون نوعية العقد صيانة ضمان تلقائياً).
              </p>
              <div className="flex gap-3 pt-4">
                <button
                  onClick={() => handleGuaranteeModalChoice(true)}
                  className="flex-1 bg-[#977e2b] hover:bg-[#b89635] text-white py-3 rounded-xl font-bold transition-all"
                >
                  نعم، عقد ضمان
                </button>
                <button
                  onClick={() => handleGuaranteeModalChoice(false)}
                  className="flex-1 bg-gray-100 text-gray-700 py-3 rounded-xl font-bold hover:bg-gray-200 transition-colors"
                >
                  لا، صيانة عادية
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}
