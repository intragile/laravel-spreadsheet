-- Opens a file in Microsoft Excel for Mac and prints bold and interior color of the header cell
-- (A1) and of the first data cell (A2), then closes the workbook.
--
-- Usage: osascript excel-style-probe.applescript /absolute/path/inside/the/Excel/container/file
-- The file must lie inside ~/Library/Containers/com.microsoft.Excel/Data/.

on run argv
	with timeout of 45 seconds
		tell application "Microsoft Excel"
			open (POSIX file (item 1 of argv))
			set ws to active sheet of active workbook
			set out to ""
			repeat with a in {"A1", "A2"}
				set r to range a of ws
				set out to out & a & " bold=" & (bold of font object of r) & " fill=" & (color of interior object of r as string) & linefeed
			end repeat
			close active workbook saving no
			return out
		end tell
	end timeout
end run
