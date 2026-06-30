import React from "react";
import { getMaintenanceContracts } from "@/app/actions/maintenanceActions";
import MaintenanceClient from "./MaintenanceClient";

export const metadata = {
  title: "عقود الصيانة - لوحة التحكم",
};

export default async function MaintenancePage() {
  const contracts = await getMaintenanceContracts();

  return (
    <main className="min-h-screen bg-slate-50/50">
      <MaintenanceClient initialContracts={contracts} />
    </main>
  );
}
