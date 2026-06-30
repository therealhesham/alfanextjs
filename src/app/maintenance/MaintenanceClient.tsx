"use client";

import React, { useState, useMemo } from "react";
import { MaintenanceContractData } from "@/app/actions/maintenanceActions";
import ContractCard from "@/components/maintenance/ContractCard";
import ContractTableRow from "@/components/maintenance/ContractTableRow";
import { 
  Search, 
  LayoutGrid, 
  List, 
  Archive, 
  ShieldCheck, 
  AlertCircle,
  Settings
} from "lucide-react";

interface MaintenanceClientProps {
  initialContracts: MaintenanceContractData[];
}

export default function MaintenanceClient({ initialContracts }: MaintenanceClientProps) {
  const [viewMode, setViewMode] = useState<'grid' | 'table'>('table');
  const [searchTerm, setSearchTerm] = useState('');
  const [showArchived, setShowArchived] = useState(false);

  const filteredContracts = useMemo(() => {
    return initialContracts.filter(contract => {
      // Filter by archive status
      if (!showArchived && contract.is_hidden) return false;
      if (showArchived && !contract.is_hidden) return false;

      // Filter by search term
      if (searchTerm) {
        const lowerTerm = searchTerm.toLowerCase();
        return (
          contract.project_name?.toLowerCase().includes(lowerTerm) ||
          contract.client_name?.toLowerCase().includes(lowerTerm) ||
          contract.technician_name?.toLowerCase().includes(lowerTerm) ||
          contract.id.includes(lowerTerm)
        );
      }

      return true;
    });
  }, [initialContracts, searchTerm, showArchived]);

  // Dynamic Active Status based on End Date
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  
  const isContractActive = (endDate: Date | string | null | undefined) => {
    if (!endDate) return false;
    return new Date(endDate) >= now;
  };

  // Stats
  const totalActive = initialContracts.filter(c => !c.is_hidden && isContractActive(c.end_date)).length;
  const totalArchived = initialContracts.filter(c => c.is_hidden).length;
  const totalGuarantee = initialContracts.filter(c => !c.is_hidden && isContractActive(c.end_date) && c.is_guarantee).length;
  const totalExpired = initialContracts.filter(c => !c.is_hidden && !isContractActive(c.end_date)).length;

  return (
    <div className="p-4 md:p-6 lg:p-8 max-w-7xl mx-auto space-y-6" dir="rtl">
      
      {/* Hero Header */}
      <div className="flex flex-wrap justify-between items-center gap-4 bg-gradient-to-br from-white to-slate-50 p-6 rounded-2xl shadow-sm border border-[#977e2b]/10">
        <div className="flex items-center gap-4">
          <div className="w-14 h-14 rounded-xl bg-gradient-to-br from-[#977e2b] to-[#b89635] text-white flex items-center justify-center shadow-lg shadow-[#977e2b]/20">
            <Settings size={28} />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-[#1e293b] m-0">عقود الصيانة</h1>
            <p className="text-sm text-slate-500 mt-1">إدارة ومتابعة عقود الصيانة الدورية والشاملة</p>
          </div>
        </div>
      </div>

      {/* Stats Bar */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-[#977e2b]/10 text-[#977e2b] flex items-center justify-center">
            <Settings size={24} />
          </div>
          <div>
            <div className="text-2xl font-bold text-[#1e293b]">{totalActive}</div>
            <div className="text-xs font-semibold text-slate-500">عقود سارية</div>
          </div>
        </div>
        
        <div className="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center">
            <ShieldCheck size={24} />
          </div>
          <div>
            <div className="text-2xl font-bold text-[#1e293b]">{totalGuarantee}</div>
            <div className="text-xs font-semibold text-slate-500">عقود ضمان</div>
          </div>
        </div>

        <div className="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center">
            <AlertCircle size={24} />
          </div>
          <div>
            <div className="text-2xl font-bold text-[#1e293b]">{totalExpired}</div>
            <div className="text-xs font-semibold text-slate-500">منتهية</div>
          </div>
        </div>

        <div className="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center">
            <Archive size={24} />
          </div>
          <div>
            <div className="text-2xl font-bold text-[#1e293b]">{totalArchived}</div>
            <div className="text-xs font-semibold text-slate-500">مؤرشفة</div>
          </div>
        </div>
      </div>

      {/* Toolbar */}
      <div className="bg-white rounded-xl p-4 border border-slate-200 shadow-sm flex flex-wrap gap-4 items-center justify-between">
        <div className="flex flex-1 min-w-[280px] gap-4 items-center">
          {/* Search */}
          <div className="relative flex-1 max-w-sm">
            <input 
              type="text" 
              placeholder="ابحث برقم العقد، اسم المشروع، العميل..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full pl-4 pr-10 py-2.5 rounded-full border border-slate-200 focus:border-[#977e2b] focus:ring-2 focus:ring-[#977e2b]/20 outline-none transition-all text-sm"
            />
            <Search size={18} className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
          </div>

          {/* Filters (Archive Toggle) */}
          <button 
            onClick={() => setShowArchived(!showArchived)}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-full border text-sm font-semibold transition-all ${showArchived ? 'bg-[#977e2b] text-white border-[#977e2b] shadow-md shadow-[#977e2b]/20' : 'bg-white text-slate-600 border-slate-200 hover:border-[#977e2b] hover:text-[#977e2b]'}`}
          >
            <Archive size={16} />
            <span>الأرشيف</span>
            <span className={`flex items-center justify-center min-w-[20px] h-[20px] rounded-full text-[10px] ${showArchived ? 'bg-white text-[#977e2b]' : 'bg-rose-500 text-white'}`}>
              {totalArchived}
            </span>
          </button>
        </div>

        <div className="flex items-center gap-4">
          <div className="text-sm text-slate-500">
            النتائج: <strong className="text-[#1e293b]">{filteredContracts.length}</strong>
          </div>
          
          {/* View Toggles */}
          <div className="flex items-center bg-slate-100 rounded-lg p-1">
            <button 
              onClick={() => setViewMode('grid')}
              className={`p-1.5 rounded-md transition-colors ${viewMode === 'grid' ? 'bg-[#977e2b] text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
            >
              <LayoutGrid size={18} />
            </button>
            <button 
              onClick={() => setViewMode('table')}
              className={`p-1.5 rounded-md transition-colors ${viewMode === 'table' ? 'bg-[#977e2b] text-white shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
            >
              <List size={18} />
            </button>
          </div>
        </div>
      </div>

      {/* Content */}
      {filteredContracts.length === 0 ? (
        <div className="bg-white rounded-xl p-12 border border-slate-200 text-center text-slate-500">
          <Settings size={48} className="mx-auto mb-4 opacity-20" />
          <h3 className="text-lg font-semibold text-slate-700 mb-1">لا توجد نتائج</h3>
          <p className="text-sm">لم يتم العثور على عقود تطابق بحثك</p>
        </div>
      ) : (
        viewMode === 'grid' ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {filteredContracts.map(contract => (
              <ContractCard key={contract.id} contract={contract} />
            ))}
          </div>
        ) : (
          <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm text-right">
                <thead className="bg-gradient-to-b from-[#977e2b] to-[#7a6522] text-white text-xs uppercase font-semibold">
                  <tr>
                    <th className="p-4 rounded-tr-lg">رقم</th>
                    <th className="p-4">المشروع</th>
                    <th className="p-4">العميل</th>
                    <th className="p-4">الفني</th>
                    <th className="p-4">النوع</th>
                    <th className="p-4">البداية</th>
                    <th className="p-4">النهاية</th>
                    <th className="p-4">القيمة</th>
                    <th className="p-4">الحالة</th>
                    <th className="p-4 rounded-tl-lg">إجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredContracts.map(contract => (
                    <ContractTableRow key={contract.id} contract={contract} />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )
      )}
    </div>
  );
}
