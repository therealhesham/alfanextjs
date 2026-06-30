import React from "react";
import { MaintenanceContractData } from "@/app/actions/maintenanceActions";
import { 
  Building2, 
  User, 
  Wrench, 
  Calendar, 
  FileText,
  MessageSquare
} from "lucide-react";

interface ContractCardProps {
  contract: MaintenanceContractData;
}

export default function ContractCard({ contract }: ContractCardProps) {
  const isContractActive = contract.end_date ? new Date(contract.end_date) >= new Date(new Date().setHours(0,0,0,0)) : false;
  
  return (
    <div className={`bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-slate-200 overflow-hidden border-r-4 ${isContractActive ? 'border-r-[#977e2b]' : 'border-r-slate-400 opacity-90'}`}>
      <div className="p-5">
        {/* Header */}
        <div className="flex justify-between items-start mb-3">
          <div className="flex-1">
            <h3 className="font-bold text-[#1e293b] text-lg leading-tight mb-1">
              {contract.project_name}
            </h3>
            <div className="text-sm font-semibold text-slate-500 flex items-center gap-1.5">
              <span className="bg-slate-100 px-2 py-0.5 rounded text-xs">
                #{contract.id}
              </span>
              {contract.is_guarantee && (
                <span className="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-xs">
                  ضمان
                </span>
              )}
              {contract.is_hidden && (
                <span className="bg-slate-200 text-slate-600 px-2 py-0.5 rounded text-xs">
                  مؤرشف
                </span>
              )}
              {!isContractActive && !contract.is_hidden && (
                <span className="bg-rose-100 text-rose-600 px-2 py-0.5 rounded text-xs">
                  منتهي
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Details Grid */}
        <div className="grid gap-2 text-sm text-slate-600 mt-4">
          <div className="flex items-center gap-2">
            <User size={16} className="text-[#977e2b]" />
            <span className="truncate">{contract.client_name}</span>
          </div>
          <div className="flex items-center gap-2">
            <Wrench size={16} className="text-[#977e2b]" />
            <span className="truncate">{contract.technician_name}</span>
          </div>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <FileText size={16} className="text-[#977e2b]" />
              <span>{contract.is_guarantee ? 'ضمان' : 'صيانة'}</span>
            </div>
            <div className="font-semibold text-slate-700">
              {contract.total_amount ? `${contract.total_amount} ر.س` : 'غير محدد'}
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="mt-5 pt-4 border-t border-slate-100 flex items-center justify-between">
          <div className="flex items-center gap-1.5 text-xs text-slate-500">
            <Calendar size={14} />
            <span>
              {contract.start_date ? new Date(contract.start_date).toLocaleDateString('ar-SA') : '-'} 
              {' إلی '} 
              {contract.end_date ? new Date(contract.end_date).toLocaleDateString('ar-SA') : '-'}
            </span>
          </div>
          
          <button className="flex items-center gap-1.5 bg-gradient-to-br from-[#977e2b] to-[#b89635] text-white px-3 py-1 rounded-full text-xs font-semibold hover:shadow-lg transition-all hover:-translate-y-0.5">
            <MessageSquare size={12} />
            ملاحظات
            {contract.notes_count > 0 && (
              <span className="bg-white/30 px-1.5 rounded-full text-[10px]">
                {contract.notes_count}
              </span>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
