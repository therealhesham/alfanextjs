"use client";

import React, { useState, useEffect, useRef } from "react";
import Link from "next/link";
import { getMaintenanceContracts, MaintenanceContractData } from "@/app/actions/maintenanceActions";
import ContractCard from "@/components/maintenance/ContractCard";
import ContractTableRow from "@/components/maintenance/ContractTableRow";
import { 
  Search, 
  LayoutGrid, 
  List, 
  Archive, 
  ShieldCheck, 
  AlertCircle,
  Settings,
  Loader2
} from "lucide-react";

interface MaintenanceClientProps {
  initialContracts: MaintenanceContractData[];
  initialStats: {
    totalActive: number;
    totalArchived: number;
    totalGuarantee: number;
    totalExpired: number;
  };
}

export default function MaintenanceClient({ initialContracts, initialStats }: MaintenanceClientProps) {
  const [viewMode, setViewMode] = useState<'grid' | 'table'>('table');
  const [searchTerm, setSearchTerm] = useState('');
  const [showArchived, setShowArchived] = useState(false);

  // Pagination and server-side state
  const [contracts, setContracts] = useState<MaintenanceContractData[]>(initialContracts);
  const [page, setPage] = useState(1);
  const [hasMore, setMore] = useState(initialContracts.length >= 20);
  const [isLoading, setIsLoading] = useState(false);
  const [isSearching, setIsSearching] = useState(false);

  const loadMore = async () => {
    if (isLoading || !hasMore) return;
    setIsLoading(true);
    try {
      const nextPage = page + 1;
      const newContracts = await getMaintenanceContracts(nextPage, 20, searchTerm, showArchived);
      if (newContracts.length > 0) {
        setContracts(prev => [...prev, ...newContracts]);
        setPage(nextPage);
        setMore(newContracts.length >= 20);
      } else {
        setMore(false);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setIsLoading(false);
    }
  };

  // Debounced search
  useEffect(() => {
    // Avoid double fetching on initial render
    if (searchTerm === '' && page === 1 && contracts === initialContracts) {
      return;
    }

    const delayDebounceFn = setTimeout(async () => {
      setIsSearching(true);
      try {
        const results = await getMaintenanceContracts(1, 20, searchTerm, showArchived);
        setContracts(results);
        setPage(1);
        setMore(results.length >= 20);
      } catch (err) {
        console.error(err);
      } finally {
        setIsSearching(false);
      }
    }, 500);

    return () => clearTimeout(delayDebounceFn);
  }, [searchTerm]);

  const handleArchiveToggle = async () => {
    const nextArchived = !showArchived;
    setShowArchived(nextArchived);
    setIsSearching(true);
    try {
      const results = await getMaintenanceContracts(1, 20, searchTerm, nextArchived);
      setContracts(results);
      setPage(1);
      setMore(results.length >= 20);
    } catch (err) {
      console.error(err);
    } finally {
      setIsSearching(false);
    }
  };

  // Infinite Scroll Observer
  const observerTarget = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && hasMore && !isLoading && !isSearching) {
          loadMore();
        }
      },
      { threshold: 0.1 }
    );

    if (observerTarget.current) {
      observer.observe(observerTarget.current);
    }

    return () => {
      if (observerTarget.current) {
        observer.unobserve(observerTarget.current);
      }
    };
  }, [hasMore, isLoading, isSearching, page, searchTerm, showArchived]);

  // Stats from server load
  const { totalActive, totalArchived, totalGuarantee, totalExpired } = initialStats;

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
        <div className="flex-shrink-0">
          <Link href="/maintenance/new" className="inline-flex items-center justify-center bg-[#977e2b] hover:bg-[#b89635] text-white font-bold py-2.5 px-6 rounded-full shadow-md hover:shadow-lg transition-all gap-2 text-sm">
            <span>+</span>
            <span>إضافة عقد صيانة</span>
          </Link>
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
              className="w-full pl-10 pr-10 py-2.5 rounded-full border border-slate-200 focus:border-[#977e2b] focus:ring-2 focus:ring-[#977e2b]/20 outline-none transition-all text-sm"
            />
            <Search size={18} className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
            {isSearching && (
              <Loader2 size={18} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[#977e2b] animate-spin" />
            )}
          </div>

          {/* Filters (Archive Toggle) */}
          <button 
            onClick={handleArchiveToggle}
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
            النتائج: <strong className="text-[#1e293b]">{contracts.length}</strong>
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
      {contracts.length === 0 ? (
        <div className="bg-white rounded-xl p-12 border border-slate-200 text-center text-slate-500">
          <Settings size={48} className="mx-auto mb-4 opacity-20" />
          <h3 className="text-lg font-semibold text-slate-700 mb-1">لا توجد نتائج</h3>
          <p className="text-sm">لم يتم العثور على عقود تطابق بحثك</p>
        </div>
      ) : (
        viewMode === 'grid' ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {contracts.map(contract => (
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
                  {contracts.map(contract => (
                    <ContractTableRow key={contract.id} contract={contract} />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )
      )}

      {/* Intersection Observer Target for Infinite Scroll */}
      <div ref={observerTarget} className="w-full py-6 flex justify-center items-center">
        {isLoading && (
          <div className="flex items-center gap-2 text-slate-500 font-semibold text-sm">
            <Loader2 size={20} className="animate-spin text-[#977e2b]" />
            <span>جاري تحميل المزيد من العقود...</span>
          </div>
        )}
        {!hasMore && contracts.length > 0 && (
          <div className="text-xs text-slate-400 font-semibold">
            تم تحميل جميع عقود الصيانة.
          </div>
        )}
      </div>
    </div>
  );
}
