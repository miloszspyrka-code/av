//+------------------------------------------------------------------+
//| AlphaVault.mq5 — wysyła zamknięte transakcje do Alpha Vault       |
//| Nie handluje. Tylko odczyt historii i POST do /api/mt/ea.         |
//+------------------------------------------------------------------+
#property copyright "Alpha Vault"
#property version   "1.00"
#property strict

input string InpCode     = "";                                  // Kod parowania z panelu Alpha Vault
input string InpEndpoint = "https://alphavault.pl/api/mt/ea";   // Endpoint API
input int    InpDays     = 7;                                   // Ile dni historii wysyłać
input int    InpTimerMin = 5;                                   // Co ile minut wysyłać

datetime g_lastRun = 0;

int OnInit()
{
   if(StringLen(InpCode) < 12)
   {
      Print("AlphaVault: wpisz kod parowania w ustawieniach EA.");
      return(INIT_PARAMETERS_INCORRECT);
   }
   EventSetTimer(MathMax(1, InpTimerMin) * 60);
   Print("AlphaVault: EA uruchomiony. Endpoint ", InpEndpoint);
   return(INIT_SUCCEEDED);
}

void OnDeinit(const int reason)
{
   EventKillTimer();
}

void OnTimer()
{
   if(TimeCurrent() - g_lastRun < MathMax(1, InpTimerMin) * 60)
      return;
   g_lastRun = TimeCurrent();
   SendClosedTrades();
}

void OnTick()
{
   // EA nie handluje; synchronizacja odbywa się w OnTimer.
}

void SendClosedTrades()
{
   datetime from = TimeCurrent() - MathMax(1, InpDays) * 86400;
   if(!HistorySelect(from, TimeCurrent()))
   {
      Print("AlphaVault: HistorySelect nie powiodło się, błąd ", GetLastError());
      return;
   }

   string items = "";
   int total = HistoryDealsTotal();
   int sent = 0;

   for(int i = 0; i < total && sent < 200; i++)
   {
      ulong ticket = HistoryDealGetTicket(i);
      if(ticket == 0)
         continue;

      long entry = HistoryDealGetInteger(ticket, DEAL_ENTRY);
      if(entry != DEAL_ENTRY_OUT && entry != DEAL_ENTRY_INOUT)
         continue;

      long dealType = HistoryDealGetInteger(ticket, DEAL_TYPE);
      if(dealType != DEAL_TYPE_BUY && dealType != DEAL_TYPE_SELL)
         continue;

      long positionId = HistoryDealGetInteger(ticket, DEAL_POSITION_ID);
      if(positionId == 0)
         continue;

      string symbol = HistoryDealGetString(ticket, DEAL_SYMBOL);
      double lots   = HistoryDealGetDouble(ticket, DEAL_VOLUME);
      double closeP = HistoryDealGetDouble(ticket, DEAL_PRICE);
      double profit = HistoryDealGetDouble(ticket, DEAL_PROFIT);
      double comm   = HistoryDealGetDouble(ticket, DEAL_COMMISSION);
      double swap   = HistoryDealGetDouble(ticket, DEAL_SWAP);
      datetime closeT = (datetime)HistoryDealGetInteger(ticket, DEAL_TIME);

      double openP = 0.0;
      datetime openT = closeT;
      for(int j = 0; j < total; j++)
      {
         ulong t2 = HistoryDealGetTicket(j);
         if(t2 == 0)
            continue;
         if((long)HistoryDealGetInteger(t2, DEAL_POSITION_ID) != positionId)
            continue;
         if(HistoryDealGetInteger(t2, DEAL_ENTRY) != DEAL_ENTRY_IN)
            continue;
         openP = HistoryDealGetDouble(t2, DEAL_PRICE);
         openT = (datetime)HistoryDealGetInteger(t2, DEAL_TIME);
         break;
      }
      if(openP <= 0.0)
         continue;

      string side = (dealType == DEAL_TYPE_BUY) ? "buy" : "sell";
      if(items != "")
         items += ",";
      items += StringFormat(
         "{\"ticket\":\"%I64u\",\"symbol\":\"%s\",\"side\":\"%s\",\"openTime\":%I64d,\"closeTime\":%I64d,"
         "\"lots\":%.8f,\"openPrice\":%.8f,\"closePrice\":%.8f,\"profit\":%.8f,\"commission\":%.8f,\"swap\":%.8f}",
         ticket, symbol, side, (long)openT, (long)closeT, lots, openP, closeP, profit, comm, swap
      );
      sent++;
   }

   if(sent == 0)
      return;

   string body = StringFormat("{\"code\":\"%s\",\"trades\":[%s]}", InpCode, items);
   char post[];
   char result[];
   string resultHeaders = "";
   StringToCharArray(body, post, 0, StringLen(body), CP_UTF8);

   string headers = "Content-Type: application/json\r\n";
   ResetLastError();
   int status = WebRequest("POST", InpEndpoint, headers, 15000, post, result, resultHeaders);
   if(status == -1)
   {
      Print("AlphaVault: WebRequest zablokowany. Dodaj ", InpEndpoint, " w Narzędzia → Opcje → Doradcy. Błąd ", GetLastError());
      return;
   }
   string response = CharArrayToString(result, 0, WHOLE_ARRAY, CP_UTF8);
   if(status >= 200 && status < 300)
      Print("AlphaVault: wysłano ", sent, " transakcji. Odpowiedź: ", response);
   else
      Print("AlphaVault: HTTP ", status, " — ", response);
}
//+------------------------------------------------------------------+
